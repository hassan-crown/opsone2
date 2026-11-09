<?php

use App\Access\LicensedCapabilities;
use App\Access\LicenseLimits;
use App\Ai\AiFeature;
use App\Licensing\LicensePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LicenseFactory;

uses(RefreshDatabase::class);

it('reads a license issued before ai_credits existed as zero credits and no AI capability', function () {
    $payload = LicensePayload::fromArray(LicenseFactory::payload());
    LicenseFactory::install();

    expect($payload->limits['ai_credits'])->toBe(0)
        ->and(app(LicenseLimits::class)->aiCredits())->toBe(0)
        ->and(app(LicensedCapabilities::class)->allows(AiFeature::CORE))->toBeFalse()
        ->and(app(LicensedCapabilities::class)->allows(AiFeature::PLUS))->toBeFalse();
});

it('reads ai_credits and the AI capabilities from a new license', function () {
    LicenseFactory::install([
        'capabilities' => ['ai_core'],
        'limits' => ['seats' => 5, 'branches' => 1, 'storage_mb' => 1024, 'ai_credits' => 300],
    ]);

    expect(app(LicenseLimits::class)->aiCredits())->toBe(300)
        ->and(app(LicensedCapabilities::class)->allows('ai_core'))->toBeTrue()
        ->and(app(LicensedCapabilities::class)->allows('ai_plus'))->toBeFalse()
        ->and(LicensedCapabilities::AI)->toBe(['ai_core', 'ai_plus']);
});

it('rejects a malformed ai_credits value', function (mixed $value) {
    LicensePayload::fromArray(LicenseFactory::payload(['limits' => ['seats' => 5, 'branches' => 1, 'storage_mb' => 1, 'ai_credits' => $value]]));
})->with([-1, '300', 1.5, null])->throws(InvalidArgumentException::class, 'ai_credits');

it('gives no credits in a phase that grants nothing', function () {
    LicenseFactory::install([
        'limits' => ['seats' => 5, 'branches' => 1, 'storage_mb' => 1, 'ai_credits' => 300],
        'starts_at' => now('UTC')->subDays(400)->toIso8601ZuluString(),
        'ends_at' => now('UTC')->subDays(200)->toIso8601ZuluString(),
    ]);

    expect(app(LicenseLimits::class)->aiCredits())->toBe(0);
});
