<?php

use App\Licensing\LicenseInstaller;
use App\Licensing\LicenseKeyring;
use App\Licensing\LicenseProblem;
use App\Licensing\LicenseStatus;
use App\Licensing\LicenseVerificationException;
use App\Licensing\LicenseVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const CONTRACT_DIR = __DIR__.'/../../Fixtures/license-contract/v1';

/**
 * @return array<string, mixed>
 */
function contractExpectations(): array
{
    return json_decode((string) file_get_contents(CONTRACT_DIR.'/expectations.json'), true, 32, JSON_THROW_ON_ERROR);
}

function contractEnvelope(string $relative): string
{
    return (string) file_get_contents(CONTRACT_DIR.'/'.$relative);
}

function contractVerifier(): LicenseVerifier
{
    $v = contractExpectations()['verifier'];

    // Test-only injection: the contract key is never part of config/license.php.
    $keyring = LicenseKeyring::make(null, null, false, $v['kid'].'='.$v['public_key']);

    return new LicenseVerifier($keyring, $v['host'], $v['deployment_id']);
}

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function contractCases(string $key): array
{
    $cases = [];

    foreach (contractExpectations()['cases'] as $case) {
        if (isset($case[$key])) {
            $cases[$case['name']] = [$case];
        }
    }

    return $cases;
}

it('declares the contract and version this suite understands', function () {
    $e = contractExpectations();

    expect($e['contract'])->toBe('opsone-license')->and($e['version'])->toBe(1);
});

it('derives the documented status at every boundary', function (array $case) {
    $payload = contractVerifier()->verify(contractEnvelope($case['envelope']));

    // T3: the display-only notice; an unknown kind (unknown-field) reads as none.
    expect($payload->notice?->value)->toBe($case['notice'] ?? null, "{$case['name']} notice");

    // ADR-0050 / central C-AI: optional limits.ai_credits, absent = 0.
    expect($payload->limits['ai_credits'])->toBe($case['ai_credits'] ?? 0, "{$case['name']} ai_credits");

    foreach ($case['status_at'] as $point) {
        expect($payload->statusAt(CarbonImmutable::parse($point['at']))->value)
            ->toBe($point['status'], "{$case['name']} at {$point['at']}");
    }
})->with(fn () => contractCases('status_at'));

it('rejects the envelope with the documented problem', function (array $case) {
    $problem = null;

    try {
        $payload = contractVerifier()->verify(contractEnvelope($case['envelope']));

        // "superseded" is the facility's stored-state check, not the verifier's.
        if ($case['problem'] === 'superseded') {
            $trusted = contractVerifier()->verify(contractEnvelope(
                contractExpectations()['cases'][array_search($case['trusted'], array_column(contractExpectations()['cases'], 'name'), true)]['envelope']
            ));
            $problem = $payload->issuedAt->lt($trusted->issuedAt) ? 'superseded' : null;
        }
    } catch (LicenseVerificationException $e) {
        $problem = $e->problem->value;
    }

    expect($problem)->toBe($case['problem'], $case['name']);
})->with(fn () => contractCases('problem'));

it('refuses a superseded license at install time and accepts a newer reissue', function () {
    $cases = collect(contractExpectations()['cases'])->keyBy('name');
    $path = storage_path('framework/testing/contract-'.getmypid().'-'.bin2hex(random_bytes(4)).'.json');
    @mkdir(dirname($path), 0755, true);
    config(['license.path' => $path]);
    app()->forgetScopedInstances();
    app()->bind(LicenseVerifier::class, fn () => contractVerifier());

    try {
        $installer = app(LicenseInstaller::class);
        $installer->install(contractEnvelope($cases['active']['envelope']));

        try {
            $installer->install(contractEnvelope($cases['superseded']['envelope']));
            $this->fail('A superseded license must not install.');
        } catch (LicenseVerificationException $e) {
            expect($e->problem)->toBe(LicenseProblem::Superseded);
        }

        $installer->install(contractEnvelope($cases['reissue-newer']['envelope']));
        expect(app(LicenseVerifier::class)->verify(contractEnvelope($cases['reissue-newer']['envelope']))->licenseId)->not->toBeEmpty();
    } finally {
        @unlink($path);
    }
});

it('covers every envelope file in the vectors', function () {
    $listed = array_column(contractExpectations()['cases'], 'envelope');
    $files = array_map(fn ($f) => 'envelopes/'.basename($f), glob(CONTRACT_DIR.'/envelopes/*.json') ?: []);

    expect($listed)->toEqualCanonicalizing($files);
});

it('never trusts the contract key through configuration', function () {
    expect((string) config('license.public_keys'))->not->toContain(contractExpectations()['verifier']['kid'])
        ->and(app(LicenseKeyring::class)->publicKey(contractExpectations()['verifier']['kid']))->toBeNull();
});

it('keeps LicenseStatus values aligned with the contract', function () {
    $known = array_map(fn ($c) => $c->value, LicenseStatus::cases());

    foreach (contractCases('status_at') as [$case]) {
        foreach ($case['status_at'] as $point) {
            expect($known)->toContain($point['status']);
        }
    }
});
