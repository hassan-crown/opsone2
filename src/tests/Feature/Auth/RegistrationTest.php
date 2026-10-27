<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * Staff join by invitation only (Q-16): public registration is disabled.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_feature_is_disabled()
    {
        $this->assertFalse(Features::enabled(Features::registration()));
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.store'));
    }

    public function test_registration_screen_is_not_available()
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_new_users_cannot_register()
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $this->assertFalse(User::query()->where('email', 'test@example.com')->exists());
    }
}
