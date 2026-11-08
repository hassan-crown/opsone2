<?php

use App\Licensing\Development\DevelopmentKeypair;
use App\Licensing\LicenseKeyring;
use App\Licensing\LicenseProblem;
use App\Licensing\LicenseStatus;
use App\Licensing\LicenseVerificationException;
use App\Licensing\LicenseVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * @return array{0: string, 1: string} base64 public key, raw secret key
 */
function rotationKeypair(): array
{
    $keypair = sodium_crypto_sign_keypair();

    return [base64_encode(sodium_crypto_sign_publickey($keypair)), sodium_crypto_sign_secretkey($keypair)];
}

it('trusts two active keys during a rotation, so the old envelope stays valid', function () {
    [$oldPublic, $oldSecret] = rotationKeypair();
    [$newPublic, $newSecret] = rotationKeypair();
    config([
        'license.public_key' => null,
        'license.public_keys' => "opsone-2026={$oldPublic}, opsone-2027={$newPublic}",
    ]);

    $old = LicenseFactory::placeRaw(LicenseFactory::signed([], 'opsone-2026', $oldSecret));
    expect($old->status())->toBe(LicenseStatus::Active);

    $new = LicenseFactory::placeRaw(LicenseFactory::signed([], 'opsone-2027', $newSecret));
    expect($new->status())->toBe(LicenseStatus::Active);
});

it('keeps the legacy single-key variables working next to the list', function () {
    [$oldPublic, $oldSecret] = rotationKeypair();
    [$newPublic, $newSecret] = rotationKeypair();
    config([
        'license.key_id' => 'opsone-production',
        'license.public_key' => $oldPublic,
        'license.public_keys' => "opsone-2027={$newPublic}",
    ]);

    expect(LicenseFactory::placeRaw(LicenseFactory::signed([], 'opsone-production', $oldSecret))->status())
        ->toBe(LicenseStatus::Active)
        ->and(LicenseFactory::placeRaw(LicenseFactory::signed([], 'opsone-2027', $newSecret))->status())
        ->toBe(LicenseStatus::Active);
});

it('rejects a retired key once it is removed from the list', function () {
    [$oldPublic, $oldSecret] = rotationKeypair();
    [$newPublic] = rotationKeypair();
    $keyring = LicenseKeyring::make(null, null, false, "opsone-2027={$newPublic}");
    $verifier = new LicenseVerifier($keyring, 'localhost');

    expect(fn () => $verifier->verify(LicenseFactory::signed(['domains' => ['localhost']], 'opsone-2026', $oldSecret)))
        ->toThrow(fn (LicenseVerificationException $e) => expect($e->problem)->toBe(LicenseProblem::UnknownKey));
});

it('never trusts the development key id from the list outside local and testing', function () {
    $keyring = LicenseKeyring::make(null, null, false, DevelopmentKeypair::KEY_ID.'='.DevelopmentKeypair::PUBLIC_KEY);

    expect($keyring->publicKey(DevelopmentKeypair::KEY_ID))->toBeNull();

    $verifier = new LicenseVerifier($keyring, 'localhost');
    expect(fn () => $verifier->verify(LicenseFactory::signed(['domains' => ['localhost']])))
        ->toThrow(fn (LicenseVerificationException $e) => expect($e->problem)->toBe(LicenseProblem::UnknownKey));
});

it('does not trust the development key in production even with nothing configured', function () {
    config(['license.public_key' => null, 'license.public_keys' => DevelopmentKeypair::KEY_ID.'='.DevelopmentKeypair::PUBLIC_KEY]);
    $this->app['env'] = 'production';

    expect(app(LicenseKeyring::class)->keyIds())->toBe([]);
});

it('stops trusting the development key in testing once any production key is listed', function () {
    [$public] = rotationKeypair();
    config(['license.public_key' => null, 'license.public_keys' => "opsone-2027={$public}"]);

    expect(app(LicenseKeyring::class)->keyIds())->toBe(['opsone-2027']);
});

it('ignores malformed entries without breaking the valid ones', function () {
    [$public] = rotationKeypair();
    $short = base64_encode(random_bytes(16));

    $keyring = LicenseKeyring::make(null, null, false, implode(',', [
        '',
        'no-equals-sign',
        '=',
        "={$public}",
        'empty-key=',
        'not-base64=@@@###',
        "too-short={$short}",
        " good = {$public} ",
        ',,',
    ]));

    expect($keyring->keyIds())->toBe(['good'])
        ->and($keyring->publicKey('good'))->toBe(base64_decode($public, true));
});

it('keeps the first key on a duplicate key id', function () {
    [$first] = rotationKeypair();
    [$second] = rotationKeypair();

    $keyring = LicenseKeyring::make('opsone-2026', $first, false, "opsone-2026={$second}");

    expect($keyring->publicKey('opsone-2026'))->toBe(base64_decode($first, true));
});
