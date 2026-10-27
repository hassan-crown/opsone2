<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
});

it('shows the owner every work-center section', function () {
    $this->actingAs($this->org->owner)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('widgets.tasks', fn ($tasks) => collect($tasks)->contains('my_tasks'))
            ->where('widgets.alerts', fn ($alerts) => collect($alerts)->contains('orders_attention')));
});

it('limits widgets to the member permissions', function () {
    $executor = $this->org->member('sales', [$this->org->a]);

    $this->actingAs($executor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('widgets.tasks', fn ($tasks) => ! collect($tasks)->contains('department_inbox')));
});
