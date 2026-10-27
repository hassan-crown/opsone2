<?php

use App\Actions\StateMachines\M01Invitation\T00CreateInvitation;
use App\Enums\OrganizationUnitStatus;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
});

/**
 * @return array<string, string>
 */
function licenseEndedDaysAgo(int $days): array
{
    return [
        'starts_at' => now()->subDays(365 + $days)->toIso8601ZuluString(),
        'ends_at' => now()->subDays($days)->toIso8601ZuluString(),
        'issued_at' => now()->addMinute()->toIso8601ZuluString(),
    ];
}

it('shows plan, term, capabilities and usage of the active license', function () {
    $this->org->member('manager', [$this->org->a]);

    $this->actingAs($this->org->owner)->get(route('plan.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/plan')
            ->where('plan', 'growth')
            ->where('status', 'active')
            ->where('capabilities', [
                ['key' => 'ready_goods', 'enabled' => true],
                ['key' => 'branch_comparisons', 'enabled' => true],
            ])
            ->where('usage.seats.used', 2)
            ->where('usage.seats.members', 2)
            ->where('usage.seats.pending_invitations', 0)
            ->where('usage.seats.limit', 15)
            ->where('usage.branches.used', 3)
            ->where('usage.branches.limit', 3)
            ->where('usage.storage.measured', true)
            ->where('usage.storage.used_mb', 0)
            ->where('usage.storage.limit', 25600)
            ->where('days_remaining', 355)
            ->has('ends_at'));
});

it('counts pending invitations as seats and ignores disabled branches', function () {
    app(T00CreateInvitation::class)->handle($this->org->owner, [
        'email' => 'new@example.com',
        'branch_ids' => [$this->org->a->id],
        'roles' => [['role_template_id' => Org::templateId('sales')]],
    ]);
    $this->actingAs($this->org->owner);
    Branch::query()->whereKey($this->org->c->id)->update(['status' => OrganizationUnitStatus::Disabled->value]);

    $this->get(route('plan.show'))->assertInertia(fn (Assert $page) => $page
        ->where('usage.seats.used', 2)
        ->where('usage.seats.pending_invitations', 1)
        ->where('usage.branches.used', 2));
});

it('shows a basic plan without the optional modules', function () {
    LicenseFactory::install([
        'plan' => 'basic',
        'capabilities' => [],
        'limits' => ['seats' => 5, 'branches' => 1, 'storage_mb' => 5120],
        'issued_at' => now()->addMinute()->toIso8601ZuluString(),
    ]);

    $this->actingAs($this->org->owner)->get(route('plan.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('plan', 'basic')
            ->where('capabilities', [
                ['key' => 'ready_goods', 'enabled' => false],
                ['key' => 'branch_comparisons', 'enabled' => false],
            ])
            ->where('usage.seats.limit', 5)
            ->where('usage.branches.limit', 1));
});

it('reports each license status', function (array $overrides, string $status, ?int $days) {
    LicenseFactory::install($overrides);

    $this->actingAs($this->org->owner)->get(route('plan.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', $status)
            ->where('effective_status', $status)
            ->where('days_remaining', $days));
})->with([
    'grace' => fn () => [licenseEndedDaysAgo(2), 'grace', 5],
    'wind_down' => fn () => [licenseEndedDaysAgo(10), 'wind_down', 27],
    'expired' => fn () => [licenseEndedDaysAgo(100), 'expired', 27],
]);

it('reports a provisioned license that has not started', function () {
    LicenseFactory::install(['activation_id' => null, 'starts_at' => null, 'ends_at' => null]);

    $this->actingAs($this->org->owner)->get(route('plan.show'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'provisioned')->where('days_remaining', null));
});

it('reports needs attention when the license cannot be verified', function () {
    LicenseFactory::placeRaw('not a license');
    $this->org->owner->refresh();

    $this->actingAs($this->org->owner)->get(route('plan.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'needs_attention')
            ->whereNot('problem', null));
});

it('denies the plan page to staff without P-01 and to guests', function () {
    $this->get(route('plan.show'))->assertRedirect(route('login'));

    $this->actingAs($this->org->member('sales', [$this->org->a]))->get(route('plan.show'))->assertForbidden();
    $this->actingAs($this->org->member('finance', [$this->org->a]))->get(route('plan.show'))->assertForbidden();
});

it('lets a manager see the plan', function () {
    $this->actingAs($this->org->member('manager', [$this->org->a]))->get(route('plan.show'))->assertOk();
});
