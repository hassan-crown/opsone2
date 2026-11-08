<?php

use App\Licensing\CurrentLicense;
use App\Licensing\LicenseNotice;
use App\Licensing\LicensePayload;
use App\Licensing\LicenseStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

/*
| T3 (ADR-0004 amendment): the optional display-only `notice` of a schema-1 license. It replaces the "expired" banner
| text with "administratively suspended" for a freeze, and changes no phase, mode, capability or limit. A missing,
| malformed or unknown notice is ignored, never rejected.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
});

/**
 * A license whose term ended now, with no grace or wind-down: the central app's freeze license (Q-83).
 *
 * @param  array<string, mixed>  $extra
 */
function placeFrozen(array $extra = []): CurrentLicense
{
    $now = CarbonImmutable::now('UTC');

    return LicenseFactory::place([
        'starts_at' => $now->subDays(100)->toIso8601ZuluString(),
        'ends_at' => $now->subMinute()->toIso8601ZuluString(),
        'grace_days' => 0,
        'wind_down_days' => 0,
        'issued_at' => $now->toIso8601ZuluString(),
        ...$extra,
    ]);
}

it('shares the administrative suspension notice for a frozen license and keeps it expired', function () {
    $license = placeFrozen(['notice' => ['kind' => 'administrative_suspension']]);

    expect($license->snapshot()->status)->toBe(LicenseStatus::Expired)
        ->and($license->snapshot()->notice())->toBe(LicenseNotice::AdministrativeSuspension);

    $this->actingAs($this->org->owner)->get('/orders')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('license.status', 'expired')
            ->where('license.effective_status', 'expired')
            ->where('license.mode', 'read_only')
            ->where('license.show', true)
            ->where('license.notice', 'administrative_suspension')
            ->where('license.blocked.orders_create', 'license_read_only'));
});

it('changes nothing when the notice is absent', function () {
    $license = placeFrozen();

    expect($license->snapshot()->status)->toBe(LicenseStatus::Expired)
        ->and($license->snapshot()->notice())->toBeNull();

    $this->actingAs($this->org->owner)->get('/orders')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('license.status', 'expired')
            ->where('license.mode', 'read_only')
            ->where('license.notice', null));
});

it('ignores a malformed or unknown notice instead of rejecting the license', function (mixed $notice) {
    $license = placeFrozen(['notice' => $notice]);

    expect($license->snapshot()->status)->toBe(LicenseStatus::Expired)
        ->and($license->snapshot()->problem)->toBeNull()
        ->and($license->snapshot()->notice())->toBeNull();
})->with([
    'unknown kind' => [['kind' => 'administrative_hold']],
    'kind not a string' => [['kind' => 7]],
    'kind missing' => [['reason' => 'administrative_suspension']],
    'bare string' => ['administrative_suspension'],
    'null' => [null],
]);

it('never shows the notice while the license is not expired', function () {
    $license = LicenseFactory::place(['notice' => ['kind' => 'administrative_suspension']]);

    expect($license->snapshot()->status)->toBe(LicenseStatus::Active)
        ->and($license->snapshot()->payload?->notice)->toBe(LicenseNotice::AdministrativeSuspension)
        ->and($license->snapshot()->notice())->toBeNull();

    $this->actingAs($this->org->owner)->get('/orders')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('license.status', 'active')->where('license.notice', null));
});

it('derives the same phases with or without a notice', function () {
    $base = LicenseFactory::payload(['ends_at' => '2027-01-01T09:00:00Z', 'starts_at' => '2026-01-01T09:00:00Z']);
    $plain = LicensePayload::fromArray($base);
    $noticed = LicensePayload::fromArray([...$base, 'notice' => ['kind' => 'administrative_suspension']]);

    foreach (['2025-12-01T00:00:00Z', '2026-06-01T00:00:00Z', '2027-01-03T00:00:00Z', '2027-01-20T00:00:00Z', '2027-06-01T00:00:00Z'] as $at) {
        $moment = CarbonImmutable::parse($at);
        expect($noticed->statusAt($moment))->toBe($plain->statusAt($moment))
            ->and($noticed->phaseEndsAt($moment)?->toIso8601ZuluString())->toBe($plain->phaseEndsAt($moment)?->toIso8601ZuluString());
    }

    expect($plain->notice)->toBeNull()->and($noticed->notice)->toBe(LicenseNotice::AdministrativeSuspension);
});
