<?php

namespace App\EInvoicing;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\EInvoicing\Zatca\Api\ZatcaClients;
use App\EInvoicing\Zatca\Api\ZatcaCredentials;
use App\EInvoicing\Zatca\Api\ZatcaResponse;
use App\EInvoicing\Zatca\Api\ZatcaUnavailable;
use App\EInvoicing\Zatca\Certificate;
use App\EInvoicing\Zatca\CsrBuilder;
use App\EInvoicing\Zatca\InvoiceHasher;
use App\EInvoicing\Zatca\InvoiceQr;
use App\EInvoicing\Zatca\InvoiceSigner;
use App\EInvoicing\Zatca\UblInvoice;
use App\Models\EInvoicing\EInvoice;
use App\Models\EInvoicing\ZatcaDevice;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * EGS onboarding with ZATCA (ADR-0032), owner-driven:
 *
 * 1. `start(otp)`: a fresh secp256k1 key and CSR (the key encrypted at rest), the compliance CSID requested with the
 *    OTP the owner got from the Fatoora portal; any previous live device is retired first.
 * 2. `complete()`: the six compliance samples ZATCA expects for invoice types "1100" (standard and simplified invoice,
 *    credit note and debit note), signed with the compliance CSID and checked by ZATCA, then the production CSID.
 *    Resumable: a failure keeps the device at its step with the error, and `complete()` runs again without a new OTP.
 *
 * Nothing secret is logged or audited: only step, status and error codes.
 */
final class ZatcaOnboarding
{
    public const SAMPLES = [
        'standard_invoice' => [EInvoice::STANDARD, EInvoice::KIND_INVOICE],
        'standard_credit' => [EInvoice::STANDARD, EInvoice::KIND_CREDIT],
        'standard_debit' => [EInvoice::STANDARD, EInvoice::KIND_DEBIT],
        'simplified_invoice' => [EInvoice::SIMPLIFIED, EInvoice::KIND_INVOICE],
        'simplified_credit' => [EInvoice::SIMPLIFIED, EInvoice::KIND_CREDIT],
        'simplified_debit' => [EInvoice::SIMPLIFIED, EInvoice::KIND_DEBIT],
    ];

    public function __construct(private readonly ZatcaClients $clients) {}

    public function start(User $actor, #[\SensitiveParameter] string $otp): ZatcaDevice
    {
        $settings = ZatcaSettings::current();
        if (! $settings->exists || ! $settings->profileComplete()) {
            throw new InvoiceBlocked('profile_incomplete');
        }
        $client = $this->clients->for($settings->environment);

        $serial = '1-'.config('zatca.solution_name', 'OpsOne').'|2-'.config('zatca.solution_version', '1.0').'|3-'.Str::uuid();
        $commonName = 'EGS-'.substr((string) $settings->vat_number, 0, 10).'-'.Str::lower(Str::random(6));
        $keys = CsrBuilder::generate([
            'common_name' => $commonName,
            'serial' => $serial,
            'organization' => (string) ($settings->seller_name_en ?: $settings->seller_name_ar),
            'unit' => (string) ($settings->branch_name ?: $settings->city),
            'vat_number' => (string) $settings->vat_number,
            'address' => trim($settings->building_number.' '.$settings->street.', '.$settings->district.', '.$settings->city),
            'industry' => (string) ($settings->industry ?: 'Retail'),
        ], $settings->environment);

        $device = DB::transaction(function () use ($actor, $settings, $serial, $commonName, $keys) {
            $this->retireLive($actor, 'reonboarding');
            $device = ZatcaDevice::query()->create([
                'environment' => $settings->environment,
                'status' => ZatcaDevice::DRAFT,
                'egs_serial' => $serial,
                'common_name' => $commonName,
                'csr' => $keys['csr'],
                'private_key' => $keys['private_key'],
                'live_key' => 1,
                'created_by' => $actor->id,
            ]);
            Audit::record(AuditAction::ZatcaOnboardingStarted, $device, $actor, after: [
                'device' => $device->id, 'environment' => $device->environment, 'common_name' => $commonName,
            ], label: $commonName);

            return $device;
        });

        $response = $this->call(fn () => $client->complianceCsid(CsrBuilder::encoded($keys['csr']), $otp));
        $token = $response?->string('binarySecurityToken');
        if ($response === null || ! $response->ok() || $token === null || $response->string('secret') === null) {
            $this->failed($device, $actor, 'compliance_csid', $response);

            return $device->refresh();
        }

        DB::transaction(function () use ($device, $response, $token) {
            $device->forceFill([
                'status' => ZatcaDevice::COMPLIANCE,
                'compliance_request_id' => $response->string('requestID'),
                'compliance_token' => $token,
                'compliance_secret' => $response->string('secret'),
                'last_error' => null,
            ])->save();
        });

        return $this->complete($device->refresh(), $actor);
    }

    public function complete(ZatcaDevice $device, User $actor): ZatcaDevice
    {
        if ($device->status !== ZatcaDevice::COMPLIANCE || $device->compliance_token === null || $device->compliance_secret === null) {
            throw new InvalidArgumentException('The device is not waiting for compliance checks.');
        }
        $client = $this->clients->for($device->environment);
        $credentials = new ZatcaCredentials($device->compliance_token, $device->compliance_secret);
        $certificate = Certificate::from($device->compliance_token);

        $checks = [];
        $previous = InvoiceHasher::FIRST_PIH;
        $icv = 0;
        foreach (self::SAMPLES as $name => [$type, $kind]) {
            $sample = $this->sample($type, $kind, ++$icv, $previous);
            $signed = InvoiceSigner::sign(UblInvoice::build($sample, $kind === EInvoice::KIND_INVOICE ? null : 'SAMPLE-INV-1'), $device->private_key, $certificate, CarbonImmutable::now()->format('Y-m-d\TH:i:s'), InvoiceQr::fields($sample), $type === EInvoice::SIMPLIFIED);
            $response = $this->call(fn () => $client->complianceCheck($credentials, $sample->uuid, $signed['hash'], $signed['xml']));
            $checks[$name] = $response !== null && $response->ok() ? ($response->warnings() ? 'passed_with_warnings' : 'passed') : 'failed';
            if ($checks[$name] === 'failed') {
                DB::transaction(fn () => $device->forceFill(['compliance_checks' => $checks])->save());
                $this->failed($device, $actor, 'compliance_check:'.$name, $response);

                return $device->refresh();
            }
            $previous = $signed['hash'];
        }

        $response = $this->call(fn () => $client->productionCsid($credentials, (string) $device->compliance_request_id));
        $token = $response?->string('binarySecurityToken');
        if ($response === null || ! $response->ok() || $token === null || $response->string('secret') === null) {
            DB::transaction(fn () => $device->forceFill(['compliance_checks' => $checks])->save());
            $this->failed($device, $actor, 'production_csid', $response);

            return $device->refresh();
        }

        $expires = openssl_x509_parse(Certificate::from($token)->pem())['validTo_time_t'] ?? null;
        DB::transaction(function () use ($device, $actor, $checks, $response, $token, $expires) {
            $device->forceFill([
                'status' => ZatcaDevice::ACTIVE,
                'compliance_checks' => $checks,
                'production_token' => $token,
                'production_secret' => $response->string('secret'),
                'certificate_expires_at' => is_int($expires) ? CarbonImmutable::createFromTimestamp($expires) : null,
                'onboarded_at' => now(),
                'last_error' => null,
            ])->save();
            Audit::record(AuditAction::ZatcaOnboarded, $device, $actor, after: [
                'device' => $device->id, 'environment' => $device->environment, 'checks' => $checks,
            ], label: $device->common_name);
        });

        return $device->refresh();
    }

    /**
     * Retires the live device (environment change or a new onboarding). Its invoices keep pointing at it.
     */
    public function retireLive(User $actor, string $reason): void
    {
        $live = ZatcaDevice::query()->where('live_key', 1)->lockForUpdate()->first();
        if ($live === null) {
            return;
        }
        $live->forceFill(['status' => ZatcaDevice::RETIRED, 'live_key' => null, 'retired_at' => now()])->save();
        Audit::record(AuditAction::ZatcaDeviceRetired, $live, $actor, after: ['device' => $live->id, 'reason' => $reason], label: $live->common_name);
    }

    /**
     * A sample document for the compliance checks: the seller profile, a sample buyer and one 115.00 SAR line.
     */
    private function sample(string $type, string $kind, int $icv, string $previous): EInvoice
    {
        $settings = ZatcaSettings::current();
        $now = CarbonImmutable::now();
        $content = InvoiceContent::amountLine('Compliance sample', 11_500, 1500);

        return new EInvoice([
            'uuid' => (string) Str::uuid(),
            'number' => 'SAMPLE-'.strtoupper(substr($kind, 0, 3)).'-'.$icv,
            'kind' => $kind,
            'type' => $type,
            'reason' => $kind === EInvoice::KIND_INVOICE ? null : 'Compliance sample adjustment',
            'issue_date' => $now->format('Y-m-d'),
            'issue_time' => $now->format('H:i:s'),
            'currency' => 'SAR',
            'seller' => $settings->sellerSnapshot(),
            'buyer' => [
                'name' => 'Compliance Sample Buyer',
                'vat_number' => $type === EInvoice::STANDARD ? '399999999800003' : null,
                'commercial_registration' => null,
                'street' => 'King Fahd Road', 'building_number' => '1234', 'additional_number' => null,
                'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12211', 'country' => 'SA',
            ],
            'lines' => $content['lines'],
            'vat_rate_bp' => $content['rate_bp'],
            'net_minor' => $content['net_minor'],
            'vat_minor' => $content['vat_minor'],
            'total_minor' => $content['total_minor'],
            'payment_means_code' => '10',
            'icv' => $icv,
            'previous_hash' => $previous,
        ]);
    }

    /**
     * @param  \Closure(): ZatcaResponse  $call
     */
    private function call(\Closure $call): ?ZatcaResponse
    {
        try {
            return $call();
        } catch (ZatcaUnavailable $e) {
            Log::warning('ZATCA onboarding call failed', ['exception' => $e::class]);

            return null;
        }
    }

    private function failed(ZatcaDevice $device, User $actor, string $step, ?ZatcaResponse $response): void
    {
        $codes = $response === null ? ['unavailable'] : array_column($response->messages('errorMessages'), 'code');
        foreach ((array) ($response?->body['errors'] ?? []) as $error) {
            if (is_array($error) && isset($error['code'])) {
                $codes[] = mb_substr((string) $error['code'], 0, 80);
            }
        }
        $error = mb_substr($step.': '.($response === null ? 'unavailable' : 'HTTP '.$response->status).($codes === [] ? '' : ' ('.implode(', ', $codes).')'), 0, 500);

        DB::transaction(function () use ($device, $actor, $step, $error) {
            $device->forceFill(['last_error' => $error])->save();
            Audit::record(AuditAction::ZatcaOnboardingFailed, $device, $actor, after: ['device' => $device->id, 'step' => $step, 'error' => $error], label: $device->common_name);
        });
    }
}
