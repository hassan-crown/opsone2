<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
});

it('redirects guests to sign in', function () {
    $this->get(route('settings.home'))->assertRedirect(route('login'));
});

it('shows the facility sections to the owner', function () {
    $this->actingAs($this->org->owner)->get(route('settings.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/settings-home')
            ->where('can.editProfile', true)
            ->where('can.viewPlan', true)
            ->where('can.transferOwnership', true)
            ->where('plan.name', 'growth')
            ->where('plan.seats_limit', 15)
            ->where('pendingTransferForMe', false));
});

it('shows only my account to a member without facility permissions', function () {
    $sales = $this->org->member('sales', [$this->org->a]);

    $this->actingAs($sales)->get(route('settings.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.editProfile', false)
            ->where('can.viewPlan', false)
            ->where('can.transferOwnership', false));
});

it('flags a pending ownership request for its recipient', function () {
    $manager = $this->org->member('manager', [$this->org->a]);
    $this->actingAs($this->org->owner)->post(route('ownership.store'), [
        'recipient_id' => Org::membershipOf($manager)->id,
        'current_password' => 'password',
    ])->assertRedirect(route('ownership.show'));

    $this->actingAs($manager)->get(route('settings.home'))
        ->assertInertia(fn (Assert $page) => $page->where('pendingTransferForMe', true));
});
