<?php

namespace App\EInvoicing;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\EInvoicing\Zatca\Certificate;
use App\EInvoicing\Zatca\InvoiceHasher;
use App\EInvoicing\Zatca\InvoiceQr;
use App\EInvoicing\Zatca\InvoiceSigner;
use App\EInvoicing\Zatca\UblInvoice;
use App\Enums\CustomerKind;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\EInvoicing\EInvoice;
use App\Models\EInvoicing\ZatcaDevice;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\Facility;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Models\User;
use App\Notifications\Outbox;
use App\Support\GaplessSequence;
use App\Support\LockingTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Issues ZATCA documents (ADR-0033) — the only writer of `e_invoices`. In one transaction under the order lock: the
 * gapless number (INV/CRN/DBN-YYYY-000001), a UUID, the next ICV of the device's chain and the previous document's
 * hash (PIH), the UBL XML and its hash, the signature and phase 2 QR once a device is onboarded (else the phase 1 QR),
 * the audit entries and — when signed — the outbox message that reports or clears it. Nothing is ever updated
 * afterwards except the submission outcome.
 */
final class InvoiceIssuer
{
    public const ICV_SEQUENCE = 'zatca_icv';

    public function __construct(private readonly GaplessSequence $sequence, private readonly Outbox $outbox) {}

    /**
     * The order's tax invoice (one per order). Idempotent: an existing invoice is returned unchanged.
     */
    public function invoiceForOrder(Order $order, ?Payment $payment, ?User $actor, string $source = TransitionRecorder::SOURCE_WEB): EInvoice
    {
        return LockingTransaction::run(function () use ($order, $payment, $actor, $source) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = EInvoice::query()->where('order_invoice_key', $order->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $settings = $this->settings();
            $content = InvoiceContent::forOrder($order) ?? throw new InvoiceBlocked('no_priced_content');
            if ($content['total_minor'] <= 0) {
                throw new InvoiceBlocked('no_priced_content');
            }
            $customer = Customer::query()->findOrFail($order->customer_id);
            $type = self::typeFor($order, $customer);

            return $this->issue($settings, [
                'kind' => EInvoice::KIND_INVOICE,
                'type' => $type,
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'payment_id' => $payment?->id,
                'order_invoice_key' => $order->id,
                'buyer' => self::buyer($customer, $type),
                'payment_means_code' => self::meansCode($payment?->method),
            ], $content, null, $order, $actor, $source);
        });
    }

    /**
     * A credit note against an invoice (refund, return, cancellation or a manual correction), at most what is still
     * credited: invoice + debit notes − credit notes. With a refund it is idempotent per refund.
     */
    public function creditNote(EInvoice $original, int $grossMinor, string $reason, ?Refund $refund, ?User $actor, string $source = TransitionRecorder::SOURCE_WEB): EInvoice
    {
        return LockingTransaction::run(function () use ($original, $grossMinor, $reason, $refund, $actor, $source) {
            $order = Order::query()->lockForUpdate()->findOrFail($original->order_id);
            if ($refund !== null && ($existing = EInvoice::query()->where('refund_id', $refund->id)->first()) !== null) {
                return $existing;
            }
            $settings = $this->settings();
            $amount = min($grossMinor, self::creditable($original));
            if ($amount <= 0) {
                throw new InvoiceBlocked('nothing_to_credit');
            }
            $label = __('einvoicing.note.credit_line', ['number' => $original->number], 'ar');

            return $this->issue($settings, [
                'kind' => EInvoice::KIND_CREDIT,
                'type' => $original->type,
                'order_id' => $order->id,
                'customer_id' => $original->customer_id,
                'refund_id' => $refund?->id,
                'payment_id' => $refund?->payment_id,
                'original_invoice_id' => $original->id,
                'reason' => mb_substr(trim($reason), 0, 1000),
                'buyer' => $original->buyer,
                'payment_means_code' => self::meansCode($refund?->method),
            ], InvoiceContent::amountLine($label, $amount, $original->vat_rate_bp), $original, $order, $actor, $source);
        });
    }

    /**
     * A debit note raising an invoice's value (e.g. an agreement change after invoicing), with its reason.
     */
    public function debitNote(EInvoice $original, int $grossMinor, string $reason, ?User $actor, string $source = TransitionRecorder::SOURCE_WEB): EInvoice
    {
        return LockingTransaction::run(function () use ($original, $grossMinor, $reason, $actor, $source) {
            $order = Order::query()->lockForUpdate()->findOrFail($original->order_id);
            $settings = $this->settings();
            if ($grossMinor <= 0) {
                throw new InvoiceBlocked('no_priced_content');
            }
            $label = __('einvoicing.note.debit_line', ['number' => $original->number], 'ar');

            return $this->issue($settings, [
                'kind' => EInvoice::KIND_DEBIT,
                'type' => $original->type,
                'order_id' => $order->id,
                'customer_id' => $original->customer_id,
                'original_invoice_id' => $original->id,
                'reason' => mb_substr(trim($reason), 0, 1000),
                'buyer' => $original->buyer,
                'payment_means_code' => '10',
            ], InvoiceContent::amountLine($label, $grossMinor, $original->vat_rate_bp), $original, $order, $actor, $source);
        });
    }

    /**
     * What a credit note may still take from an invoice: its total + debit notes − credit notes.
     */
    public static function creditable(EInvoice $original): int
    {
        $notes = EInvoice::query()->where('original_invoice_id', $original->id)->get(['kind', 'total_minor']);
        $debits = (int) $notes->where('kind', EInvoice::KIND_DEBIT)->sum('total_minor');
        $credits = (int) $notes->where('kind', EInvoice::KIND_CREDIT)->sum('total_minor');

        return $original->total_minor + $debits - $credits;
    }

    /**
     * Standard (B2B, cleared) for an organization with a VAT number unless the order asked for a simplified invoice;
     * simplified (B2C, reported) otherwise.
     */
    public static function typeFor(Order $order, Customer $customer): string
    {
        $business = $customer->kind === CustomerKind::Organization && is_string($customer->vat_number) && $customer->vat_number !== '';

        return $business && $order->invoice_type !== 'simplified' ? EInvoice::STANDARD : EInvoice::SIMPLIFIED;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buyer(Customer $customer, string $type): array
    {
        $address = is_array($customer->national_address) ? $customer->national_address : [];
        $buyer = [
            'name' => $customer->name,
            'vat_number' => $type === EInvoice::STANDARD ? $customer->vat_number : null,
            'commercial_registration' => $customer->kind === CustomerKind::Organization ? $customer->commercial_registration : null,
            'street' => (string) ($address['street'] ?? ''),
            'building_number' => (string) ($address['building_number'] ?? ''),
            'additional_number' => $address['additional_number'] ?? null,
            'district' => (string) ($address['district'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'postal_code' => (string) ($address['postal_code'] ?? ''),
            'country' => 'SA',
        ];
        if ($type === EInvoice::STANDARD) {
            foreach (['street', 'building_number', 'district', 'city', 'postal_code'] as $key) {
                if ($buyer[$key] === '') {
                    throw new InvoiceBlocked('buyer_address_missing');
                }
            }
        }

        return $buyer;
    }

    /**
     * UNTDID 4461: 10 cash, 30 credit transfer, 48 bank card, 20 cheque; online gateway payments are card payments.
     */
    public static function meansCode(?PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::Transfer => '30',
            PaymentMethod::Card, PaymentMethod::Online => '48',
            PaymentMethod::Cheque => '20',
            default => '10',
        };
    }

    private function settings(): ZatcaSettings
    {
        $settings = ZatcaSettings::current();
        if (! $settings->exists || ! $settings->enabled) {
            throw new InvoiceBlocked('disabled');
        }
        if (! $settings->profileComplete()) {
            throw new InvoiceBlocked('profile_incomplete');
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{lines: list<array{description: string, quantity: string, unit: string|null, net_minor: int, vat_minor: int, gross_minor: int}>, rate_bp: int, net_minor: int, vat_minor: int, total_minor: int}  $content
     */
    private function issue(ZatcaSettings $settings, array $attributes, array $content, ?EInvoice $original, Order $order, ?User $actor, string $source): EInvoice
    {
        $timezone = Facility::current()->timezone ?? 'Asia/Riyadh';
        $now = CarbonImmutable::now();
        $local = $now->setTimezone($timezone);
        $device = ZatcaDevice::live();
        $device = $device !== null && $device->isActive() ? $device : null;

        // The device chain: ICV and PIH are taken under the chain's counter lock, so concurrent issues queue.
        $icv = $this->sequence->next(self::ICV_SEQUENCE.':'.($device->id ?? 0), 0);
        $previous = $icv === 1 ? null : EInvoice::query()
            ->when($device === null, fn ($q) => $q->whereNull('device_id'), fn ($q) => $q->where('device_id', $device->id))
            ->where('icv', $icv - 1)->value('hash');

        [$prefix, $sequence] = match ($attributes['kind']) {
            EInvoice::KIND_CREDIT => ['CRN', 'e_credit_note'],
            EInvoice::KIND_DEBIT => ['DBN', 'e_debit_note'],
            default => ['INV', 'e_invoice'],
        };

        $invoice = new EInvoice($attributes + [
            'uuid' => (string) Str::uuid(),
            'number' => $this->sequence->format($sequence, $prefix, 6),
            'issued_at' => $now,
            'issue_date' => $local->format('Y-m-d'),
            'issue_time' => $local->format('H:i:s'),
            'currency' => 'SAR',
            'seller' => $settings->sellerSnapshot(),
            'lines' => $content['lines'],
            'vat_rate_bp' => $content['rate_bp'],
            'net_minor' => $content['net_minor'],
            'vat_minor' => $content['vat_minor'],
            'total_minor' => $content['total_minor'],
            'device_id' => $device?->id,
            'icv' => $icv,
            'previous_hash' => is_string($previous) ? $previous : InvoiceHasher::FIRST_PIH,
            'issued_by' => $actor?->id,
        ]);

        $xml = UblInvoice::build($invoice, $original?->number);
        $fields = InvoiceQr::fields($invoice);
        if ($device !== null) {
            $certificate = Certificate::from((string) $device->production_token);
            $signed = InvoiceSigner::sign($xml, $device->private_key, $certificate, $local->format('Y-m-d\TH:i:s'), $fields, $invoice->type === EInvoice::SIMPLIFIED);
            $invoice->forceFill([
                'xml' => $signed['xml'],
                'hash' => $signed['hash'],
                'qr' => $signed['qr'],
                'signed' => true,
                'submission_status' => EInvoice::PENDING,
            ]);
        } else {
            $qr = InvoiceQr::phaseOne($fields);
            $invoice->forceFill([
                'xml' => str_replace(UblInvoice::QR_PLACEHOLDER, $qr, $xml),
                'hash' => InvoiceHasher::hash($xml),
                'qr' => $qr,
                'signed' => false,
                'submission_status' => EInvoice::NOT_REQUIRED,
            ]);
        }
        $invoice->save();

        $branches = [$order->branch_id];
        Audit::record(AuditAction::EInvoiceIssued, $invoice, $actor, after: [
            'number' => $invoice->number, 'uuid' => $invoice->uuid, 'kind' => $invoice->kind, 'type' => $invoice->type,
            'order' => $order->number, 'original' => $original?->number, 'total_minor' => $invoice->total_minor,
            'vat_minor' => $invoice->vat_minor, 'icv' => $invoice->icv, 'hash' => $invoice->hash,
        ], reason: $invoice->reason, source: $source, branchIds: $branches, label: $invoice->number);

        if ($invoice->signed) {
            Audit::record(AuditAction::EInvoiceSigned, $invoice, $actor, after: [
                'number' => $invoice->number, 'device' => $device?->id, 'hash' => $invoice->hash,
            ], source: $source, branchIds: $branches, label: $invoice->number);
            $this->outbox->enqueueEInvoice($invoice->id, 'zatca:'.$invoice->uuid);
        }

        return $invoice;
    }
}
