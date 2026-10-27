<?php

use App\Enums\SetupStatus;
use App\Models\Facility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
});

it('resumes at the first step that is not done and shows the checklist on the dashboard', function () {
    $this->actingAs($this->org->owner)->get(route('org.setup'))->assertRedirect(route('org.setup.step', 'org'));
    $this->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('setup.done', 0)->where('setup.next', 'org'));
});

it('walks the wizard to completion and closes it for good', function () {
    $this->actingAs($this->org->owner);

    $this->post(route('org.setup.complete'))->assertSessionHasErrors('setup');

    $this->put(route('org.setup.org'), [
        'name_ar' => 'مطابخ', 'name_en' => 'Kitchens', 'default_locale' => 'ar', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh',
    ])->assertRedirect(route('org.setup.step', 'structure'));
    $this->post(route('org.setup.skip', 'structure'))->assertRedirect(route('org.setup.step', 'flow'));
    $this->post(route('org.setup.next', 'flow'));
    $this->post(route('org.setup.skip', 'team'));

    $this->get(route('org.setup'))->assertRedirect(route('org.setup.step', 'structure'));
    $this->get(route('org.setup.step', 'review'))->assertInertia(fn (Assert $p) => $p->where('steps.structure', 'skipped')->where('steps.org', 'done'));

    $this->post(route('org.setup.complete'))->assertRedirect(route('dashboard'));
    expect(Facility::current()->setup_status)->toBe(SetupStatus::Completed);

    $this->get(route('org.setup.step', 'org'))->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('setup', null));
});

it('lets the wizard create the first branch and invite within limits', function () {
    $this->actingAs($this->org->owner)
        ->get(route('org.setup.step', 'structure'))
        ->assertInertia(fn (Assert $p) => $p->component('org/setup/structure')->where('usage.limit', 3)->where('usage.used', 3));
    $this->post(route('org.branches.store'), ['name_ar' => 'رابع', 'from' => 'wizard'])->assertSessionHasErrors('license');
});

it('denies users without facility-edit permission', function () {
    $sales = $this->org->member('sales', [$this->org->a]);
    $this->actingAs($sales)->get(route('org.setup.step', 'org'))->assertForbidden();
    $this->put(route('org.setup.org'), ['name_ar' => 'x'])->assertForbidden();
    $this->post(route('org.setup.complete'))->assertForbidden();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('setup', null));
});
