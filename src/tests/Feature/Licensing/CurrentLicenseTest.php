<?php

use App\Licensing\CurrentLicense;
use App\Licensing\LicenseEnvelope;
use App\Licensing\LicenseKeyring;
use App\Licensing\LicenseProblem;
use App\Licensing\LicenseStatus;
use App\Licensing\LicenseVerificationException;
use App\Licensing\LicenseVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\LicenseFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27T09:00:00Z'));
    config(['license.path' => storage_path('framework/testing/license-'.bin2hex(random_bytes(4)).'.json')]);
    @mkdir(dirname((string) config('license.path')), 0755, true);
});

afterEach(function () {
    @unlink((string) config('license.path'));
});

/**
 * Re-resolve the scoped service, as a new request would.
 */
function freshLicense(): CurrentLicense
{
    app()->forgetScopedInstances();

    return app(CurrentLicense::class);
}

it('trusts a valid license and exposes its capabilities and limits', function () {
    $license = LicenseFactory::place();

    expect($license->status())->toBe(LicenseStatus::Active)
        ->and($license->problem())->toBeNull()
        ->and($license->allows('ready_goods'))->toBeTrue()
        ->and($license->allows('unknown_capability'))->toBeFalse()
        ->and($license->limit('seats'))->toBe(15)
        ->and($license->limit('branches'))->toBe(3)
        ->and($license->limit('storage_mb'))->toBe(25600)
        ->and($license->limit('unknown'))->toBeNull()
        ->and($license->daysRemaining())->toBe(355)
        ->and($license->acceptsNewWork())->toBeTrue();

    expect(DB::table('license_states')->value('status'))->toBe('active');
});

it('rejects a tampered payload', function () {
    $envelope = json_decode(LicenseFactory::signed(), true);
    $payload = json_decode((string) LicenseEnvelope::decode($envelope['payload']), true);
    $payload['limits']['seats'] = 500;
    $envelope['payload'] = LicenseEnvelope::encode((string) json_encode($payload));

    $license = LicenseFactory::placeRaw((string) json_encode($envelope));

    expect($license->status())->toBe(LicenseStatus::NeedsAttention)
        ->and($license->problem())->toBe(LicenseProblem::BadSignature)
        ->and($license->allows('ready_goods'))->toBeFalse()
        ->and($license->limit('seats'))->toBeNull();
});

it('rejects a license signed by another key', function () {
    $keypair = sodium_crypto_sign_keypair();
    $license = LicenseFactory::placeRaw(LicenseFactory::signed([], 'opsone-dev-insecure', sodium_crypto_sign_secretkey($keypair)));

    expect($license->problem())->toBe(LicenseProblem::BadSignature);
});

it('rejects a license for another domain', function () {
    $license = LicenseFactory::place(['domains' => ['other.opsone.sa']]);

    expect($license->status())->toBe(LicenseStatus::NeedsAttention)
        ->and($license->problem())->toBe(LicenseProblem::DomainMismatch);
});

it('rejects a license for another deployment when one is configured', function () {
    config(['license.deployment_id' => 'deployment-2']);

    expect(LicenseFactory::place()->problem())->toBe(LicenseProblem::DeploymentMismatch);
});

it('rejects an unsupported schema version', function () {
    expect(LicenseFactory::place(['schema_version' => 2])->problem())->toBe(LicenseProblem::UnsupportedSchema);
});

it('rejects a signed but incomplete payload', function () {
    expect(LicenseFactory::place(['limits' => ['seats' => 5]])->problem())->toBe(LicenseProblem::InvalidPayload);
});

it('grants no service before the term starts', function () {
    $license = LicenseFactory::place([
        'starts_at' => '2026-11-01T00:00:00Z',
        'ends_at' => '2027-11-01T00:00:00Z',
    ]);

    expect($license->status())->toBe(LicenseStatus::Provisioned)
        ->and($license->allows('ready_goods'))->toBeFalse()
        ->and($license->acceptsNewWork())->toBeFalse()
        ->and($license->daysRemaining())->toBe(5);
});

it('grants no service to an unactivated provisioning license', function () {
    $license = LicenseFactory::place(['activation_id' => null, 'starts_at' => null, 'ends_at' => null]);

    expect($license->status())->toBe(LicenseStatus::Provisioned)
        ->and($license->allows('ready_goods'))->toBeFalse()
        ->and($license->daysRemaining())->toBeNull();
});

it('is in grace from the first to the seventh day after the term', function (string $at, int $daysLeft) {
    $this->travelTo(CarbonImmutable::parse($at));

    $license = LicenseFactory::place([
        'starts_at' => '2025-10-01T00:00:00Z',
        'ends_at' => '2026-10-01T00:00:00Z',
        'issued_at' => '2025-10-01T00:00:00Z',
    ]);

    expect($license->status())->toBe(LicenseStatus::Grace)
        ->and($license->allows('ready_goods'))->toBeTrue()
        ->and($license->acceptsNewWork())->toBeTrue()
        ->and($license->daysRemaining())->toBe($daysLeft);
})->with([
    'day 1' => ['2026-10-01T01:00:00Z', 7],
    'day 7' => ['2026-10-07T23:00:00Z', 1],
]);

it('winds down for 30 days after grace', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T00:00:00Z'));

    $license = LicenseFactory::place([
        'starts_at' => '2025-10-01T00:00:00Z',
        'ends_at' => '2026-10-01T00:00:00Z',
        'issued_at' => '2025-10-01T00:00:00Z',
    ]);

    expect($license->status())->toBe(LicenseStatus::WindDown)
        ->and($license->allows('ready_goods'))->toBeTrue()
        ->and($license->acceptsNewWork())->toBeFalse()
        ->and($license->daysRemaining())->toBe(30);
});

it('is read-and-export only after wind-down until retention ends', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07T00:00:00Z'));

    $license = LicenseFactory::place([
        'starts_at' => '2025-10-01T00:00:00Z',
        'ends_at' => '2026-10-01T00:00:00Z',
        'issued_at' => '2025-10-01T00:00:00Z',
    ]);

    expect($license->status())->toBe(LicenseStatus::Expired)
        ->and($license->allows('ready_goods'))->toBeFalse()
        ->and($license->limit('seats'))->toBeNull()
        ->and($license->acceptsNewWork())->toBeFalse()
        ->and($license->daysRemaining())->toBe(90);
});

it('needs attention when the license file is missing', function () {
    $license = freshLicense();

    expect($license->status())->toBe(LicenseStatus::NeedsAttention)
        ->and($license->problem())->toBe(LicenseProblem::Missing)
        ->and($license->allows('ready_goods'))->toBeFalse()
        ->and($license->limit('seats'))->toBeNull()
        ->and($license->daysRemaining())->toBeNull();
});

it('needs attention when the license file is corrupt', function () {
    $license = LicenseFactory::placeRaw('{"format": "opsone-license", ');

    expect($license->status())->toBe(LicenseStatus::NeedsAttention)
        ->and($license->problem())->toBe(LicenseProblem::Corrupt)
        ->and($license->allows('ready_goods'))->toBeFalse();
});

it('keeps the last trusted state when the file is later corrupted, even after a cache flush', function () {
    LicenseFactory::place();
    Cache::flush();

    $license = LicenseFactory::placeRaw('not json');

    expect($license->status())->toBe(LicenseStatus::NeedsAttention)
        ->and($license->problem())->toBe(LicenseProblem::Corrupt)
        ->and($license->snapshot()->effectiveStatus())->toBe(LicenseStatus::Active)
        ->and($license->allows('ready_goods'))->toBeTrue()
        ->and($license->limit('seats'))->toBe(15);
});

it('needs attention when the clock moves backwards, without extending the term', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07T00:00:00Z'));
    $license = LicenseFactory::place([
        'starts_at' => '2025-10-01T00:00:00Z',
        'ends_at' => '2026-10-01T00:00:00Z',
        'issued_at' => '2025-10-01T00:00:00Z',
    ]);
    expect($license->status())->toBe(LicenseStatus::Expired);

    $this->travelTo(CarbonImmutable::parse('2026-09-01T00:00:00Z'));
    $license = freshLicense();

    expect($license->status())->toBe(LicenseStatus::NeedsAttention)
        ->and($license->problem())->toBe(LicenseProblem::ClockUntrusted)
        ->and($license->snapshot()->effectiveStatus())->toBe(LicenseStatus::Expired)
        ->and($license->allows('ready_goods'))->toBeFalse();
});

it('tolerates small clock drift', function () {
    LicenseFactory::place();
    $this->travelTo(CarbonImmutable::parse('2026-10-27T08:58:00Z'));

    expect(freshLicense()->status())->toBe(LicenseStatus::Active);
});

it('needs attention when the license was issued in the future', function () {
    expect(LicenseFactory::place(['issued_at' => '2026-10-28T09:00:00Z'])->problem())
        ->toBe(LicenseProblem::ClockUntrusted);
});

it('refuses an older license than the last trusted one', function () {
    LicenseFactory::place(['issued_at' => '2026-10-20T00:00:00Z']);
    $license = LicenseFactory::place(['issued_at' => '2026-10-10T00:00:00Z', 'limits' => ['seats' => 99, 'branches' => 9, 'storage_mb' => 1]]);

    expect($license->problem())->toBe(LicenseProblem::Superseded)
        ->and($license->limit('seats'))->toBe(15);
});

it('does not trust the development key outside local and testing', function () {
    $verifier = new LicenseVerifier(LicenseKeyring::make('opsone-production', null, false), 'localhost');

    expect(fn () => $verifier->verify(LicenseFactory::signed(['domains' => ['localhost']])))
        ->toThrow(fn (LicenseVerificationException $e) => expect($e->problem)->toBe(LicenseProblem::UnknownKey));
});

it('verifies with the production public key from config', function () {
    $keypair = sodium_crypto_sign_keypair();
    config([
        'license.key_id' => 'opsone-production-2026',
        'license.public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
    ]);

    $license = LicenseFactory::placeRaw(LicenseFactory::signed([], 'opsone-production-2026', sodium_crypto_sign_secretkey($keypair)));

    expect($license->status())->toBe(LicenseStatus::Active);
});
