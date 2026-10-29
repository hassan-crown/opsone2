<?php

use App\Access\Exceptions\LicenseLimitReached;
use App\Access\LicenseLimits;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationUnitStatus;
use App\Licensing\CurrentLicense;
use App\Licensing\LicenseStatus;
use App\Models\Branch;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

/*
| License states inside the app (P9 gap, SUB-12/13 facility side, ADR-0028): every main screen stays readable in
| every phase, the shared `license` prop carries the mode, and the main add actions report WHY they are unavailable;
| the server refuses them regardless. Downgrades block new additions and never delete or disable anything.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
});

/**
 * Puts the deployment in a license phase. needs_attention = a damaged file with no trusted license left.
 */
function licensePhase(string $status): void
{
    $now = CarbonImmutable::now('UTC');
    // Room for one more branch, so only the phase decides.
    $limits = ['limits' => ['seats' => 15, 'branches' => 5, 'storage_mb' => 1024], 'issued_at' => $now->toIso8601ZuluString()];
    $ended = fn (int $days) => [
        'starts_at' => $now->subDays($days + 365)->toIso8601ZuluString(),
        'ends_at' => $now->subDays($days)->toIso8601ZuluString(),
        ...$limits,
    ];

    match ($status) {
        'active' => LicenseFactory::place($limits),
        'grace' => LicenseFactory::place($ended(2)),
        'wind_down' => LicenseFactory::place($ended(10)),
        'expired' => LicenseFactory::place($ended(60)),
        'needs_attention' => (function () {
            DB::table('license_states')->delete();
            Cache::forget((string) config('license.cache_key', 'license.state'));
            LicenseFactory::placeRaw('{"not":"a license"}');
        })(),
    };

    expect(app(CurrentLicense::class)->refresh()->status)->toBe(LicenseStatus::from($status));
}

function blockedIs(Assert $page, string $action, ?string $reason): Assert
{
    return $reason === null ? $page->missing("license.blocked.{$action}") : $page->where("license.blocked.{$action}", $reason);
}

dataset('phases', [
    'active' => ['active', 'full', null],
    'grace' => ['grace', 'full', null],
    'wind_down' => ['wind_down', 'no_new_work', 'license_no_new_work'],
    'expired' => ['expired', 'read_only', 'license_read_only'],
    'needs_attention' => ['needs_attention', 'read_only', 'license_read_only'],
]);

it('keeps the main screens readable and explains blocked add actions', function (string $status, string $mode, ?string $newWork) {
    licensePhase($status);
    $owner = $this->org->owner;
    $readOnly = $mode === 'read_only';

    foreach (['/orders' => 'orders/index', '/customers' => 'customers/index', '/org/team' => 'org/team/index', '/org/branches' => 'org/branches', '/settings/plan' => 'account/plan', '/settings/closure' => 'account/closure'] as $url => $component) {
        $response = $this->actingAs($owner)->get($url);
        expect($response->status())->toBe(200, "{$url} in {$status}");
        $response->assertInertia(fn (Assert $page) => blockedIs(blockedIs(blockedIs(blockedIs($page
            ->component($component)
            ->where('license.status', $status)
            ->where('license.mode', $mode)
            ->where('license.show', $status !== 'active'),
            'orders_create', $newWork),
            'team_invite', $newWork),
            'customers_create', $readOnly ? 'license_read_only' : null),
            'branches_create', $readOnly ? 'license_read_only' : null));
    }
})->with('phases');

it('refuses on the server what the screens show as blocked', function (string $status, string $mode) {
    licensePhase($status);
    $owner = $this->org->owner;

    Mail::fake();
    $invite = $this->actingAs($owner)->post(route('org.team.store'), [
        'name' => 'New', 'email' => 'new-'.$status.'@example.com', 'branch_ids' => [$this->org->a->id],
        'roles' => [['role_template_id' => Org::templateId('sales'), 'scope' => null]],
    ]);
    $mode === 'full' ? $invite->assertSessionHasNoErrors()->assertRedirect() : $invite->assertForbidden();
    expect(Invitation::query()->where('email', 'new-'.$status.'@example.com')->exists())->toBe($mode === 'full');

    $branch = $this->actingAs($owner)->post(route('org.branches.store'), ['name_ar' => 'فرع '.$status]);
    $mode === 'read_only' ? $branch->assertForbidden() : $branch->assertSessionHasNoErrors()->assertRedirect();
})->with('phases');

it('blocks new seats after a downgrade without disabling anyone', function () {
    foreach (range(1, 4) as $i) {
        $this->org->member('sales', [$this->org->a]);
    }
    $active = Membership::query()->where('status', MembershipStatus::Active->value)->count();

    // Renewal on a smaller plan: fewer seats than active members.
    LicenseFactory::place(['limits' => ['seats' => 3, 'branches' => 3, 'storage_mb' => 1024], 'issued_at' => now('UTC')->toIso8601ZuluString()]);

    $this->actingAs($this->org->owner)->get('/org/team')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('license.blocked.team_invite', 'seats_limit'));

    expect(fn () => DB::transaction(fn () => app(LicenseLimits::class)->reserveSeat()))->toThrow(LicenseLimitReached::class);
    expect(Membership::query()->where('status', MembershipStatus::Active->value)->count())->toBe($active);

    // Members over the limit keep working.
    $member = Membership::query()->where('id', '!=', $this->org->facility->owner_membership_id)->firstOrFail()->user;
    $this->actingAs($member)->get('/orders')->assertOk();
});

it('blocks new branches over the downgraded limit and keeps the existing ones active', function () {
    LicenseFactory::place(['limits' => ['seats' => 15, 'branches' => 1, 'storage_mb' => 1024], 'issued_at' => now('UTC')->toIso8601ZuluString()]);

    $this->actingAs($this->org->owner)->get('/org/branches')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('license.blocked.branches_create', 'branches_limit'));

    $this->post(route('org.branches.store'), ['name_ar' => 'رابع'])->assertSessionHasErrors('license');

    expect(Branch::query()->where('status', OrganizationUnitStatus::Active->value)->count())->toBe(3);
});

it('shows nothing blocked to a member who never held the action', function () {
    licensePhase('expired');
    $viewer = $this->org->member('inventory', [$this->org->a]);

    $this->actingAs($viewer)->get('/settings')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('license.mode', 'read_only')
            ->missing('license.blocked.orders_create')
            ->missing('license.blocked.team_invite'));
});

it('keeps reports and report exports open in every license phase (reading, NFR-16)', function (string $status) {
    licensePhase($status);
    Storage::fake('attachments');
    $owner = $this->org->owner;

    foreach (['/reports', '/reports/sales', '/reports/refunds', '/reports/inventory', '/reports/exports', '/reports/collection'] as $url) {
        expect($this->actingAs($owner)->get($url)->status())->toBe(200, "{$url} in {$status}");
    }

    $this->actingAs($owner)->postJson('/reports/exports', ['report' => 'sales'])->assertCreated();
    $export = ReportExport::query()->latest('id')->firstOrFail();
    expect($export->refresh()->status)->toBe('ready');
    $this->actingAs($owner)->postJson("/reports/exports/{$export->uuid}/link")->assertOk();
})->with('phases');
