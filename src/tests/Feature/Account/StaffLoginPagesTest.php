<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the staff login without a registration link', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('canResetPassword', true)
            ->missing('canRegister'));

    expect(Route::has('register'))->toBeFalse();
    $this->get('/register')->assertNotFound();
});

it('renders forgot and reset password pages', function () {
    $this->get(route('password.request'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/forgot-password'));

    $user = User::factory()->create();
    $this->get(route('password.reset', ['token' => 'abc', 'email' => $user->email]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/reset-password')->where('token', 'abc'));
});

it('shows the login in Arabic with right-to-left direction by default', function () {
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('locale', 'ar')
        ->where('dir', 'rtl'));
});
