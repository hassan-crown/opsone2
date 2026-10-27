<?php

use App\Models\Branch;
use App\Models\RoleTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->sales = RoleTemplate::query()->where('key', 'sales')->firstOrFail();
});

it('shows the role editor grouped by matrix group and locks the owner role', function () {
    $this->actingAs($this->org->owner)->get(route('org.roles.edit', $this->sales))
        ->assertInertia(fn (Assert $p) => $p->component('org/roles')->where('canEdit', true)->has('catalogue')->has('roles', 8));
    $owner = RoleTemplate::query()->where('is_owner', true)->firstOrFail();
    $this->get(route('org.roles.edit', $owner))->assertInertia(fn (Assert $p) => $p->where('canEdit', false));
    $this->put(route('org.roles.update', $owner), ['role' => []])->assertSessionHasErrors('grants');
});

it('saves role grants and ends holders sessions immediately', function () {
    $holder = $this->org->member('sales', [$this->org->a]);
    $before = $holder->refresh()->access_version;

    $this->actingAs($this->org->owner)->put(route('org.roles.update', $this->sales), [
        'grants' => [['permission' => 'P-08', 'scope' => 'branches']],
    ])->assertRedirect();

    expect($this->sales->grants()->count())->toBe(1)->and($holder->refresh()->access_version)->toBeGreaterThan($before);
    $this->put(route('org.roles.update', $this->sales), ['grants' => [['permission' => 'P-08', 'scope' => 'own']]])
        ->assertSessionHasErrors('grants');
});

it('keeps role editing to facility-wide role managers', function () {
    $manager = $this->org->member('manager', [$this->org->a]);
    $this->actingAs($manager)->put(route('org.roles.update', $this->sales), ['grants' => []])->assertForbidden();
    $this->actingAs($this->org->member('sales', [$this->org->a]))->get(route('org.roles.edit', $this->sales))->assertForbidden();
});

it('lists branches in scope only', function () {
    $this->actingAs($this->org->owner)->get(route('org.branches.index'))
        ->assertInertia(fn (Assert $p) => $p->component('org/branches')->has('branches', 3)->where('usage.limit', 3)->where('canCreate', true));
    $manager = $this->org->member('manager', [$this->org->a]);
    $this->actingAs($manager)->get(route('org.branches.index'))
        ->assertInertia(fn (Assert $p) => $p->has('branches', 1)->where('canCreate', false));
    $this->post(route('org.branches.store'), ['name_ar' => 'x'])->assertForbidden();
    $this->post(route('org.branches.disable', $this->org->c))->assertForbidden();
});

it('creates a branch and surfaces the branch limit', function () {
    $this->actingAs($this->org->owner);
    $this->post(route('org.branches.store'), ['name_ar' => 'رابع'])->assertSessionHasErrors('license');
    expect(Branch::query()->count())->toBe(3);

    LicenseFactory::install(['limits' => ['seats' => 15, 'branches' => 5, 'storage_mb' => 1]]);
    $this->post(route('org.branches.store'), ['name_ar' => 'رابع', 'code' => 'B4'])->assertRedirect(route('org.branches.index'));
    expect(Branch::query()->count())->toBe(4);
    $this->post(route('org.branches.store'), ['name_ar' => 'x', 'code' => 'B4'])->assertSessionHasErrors('code');
});

it('disables and reactivates a branch within the limit and keeps the last one', function () {
    $this->actingAs($this->org->owner);
    $this->post(route('org.branches.disable', $this->org->c))->assertRedirect();
    expect($this->org->c->refresh()->status->value)->toBe('disabled');

    $this->post(route('org.branches.store'), ['name_ar' => 'بديل'])->assertRedirect();
    $this->post(route('org.branches.reactivate', $this->org->c))->assertSessionHasErrors('license');

    $this->post(route('org.branches.disable', $this->org->a));
    $this->post(route('org.branches.disable', $this->org->b));
    $last = Branch::query()->where('status', 'active')->sole();
    $this->post(route('org.branches.disable', $last))->assertSessionHasErrors('branch');
});

it('updates a branch', function () {
    $this->actingAs($this->org->owner)->put(route('org.branches.update', $this->org->a), ['name_ar' => 'جديد', 'code' => $this->org->a->code])
        ->assertRedirect(route('org.branches.index'));
    expect($this->org->a->refresh()->name_ar)->toBe('جديد');
});

it('exposes navigation entries only when the Gate allows', function () {
    $this->actingAs($this->org->owner)->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('orgNav.team', true)->where('orgNav.branches', true));
    $this->actingAs($this->org->member('sales', [$this->org->a]))->get(route('dashboard'))
        ->assertInertia(fn (Assert $p) => $p->where('orgNav.team', false)->where('orgNav.branches', false));
});
