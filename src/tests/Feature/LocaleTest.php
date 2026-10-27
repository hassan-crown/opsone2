<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['app.locale' => 'ar', 'app.fallback_locale' => 'en']));

it('defaults to Arabic with a right-to-left document', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<html lang="ar" dir="rtl"', false);
});

it('uses the locale cookie and flips direction for English', function () {
    $this->withUnencryptedCookie('locale', 'en')
        ->get('/')
        ->assertSee('<html lang="en" dir="ltr"', false);
});

it('ignores an unsupported locale cookie', function () {
    $this->withUnencryptedCookie('locale', 'fr')
        ->get('/')
        ->assertSee('<html lang="ar" dir="rtl"', false);
});

it('prefers the signed-in user preference over the cookie', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->withUnencryptedCookie('locale', 'ar')
        ->get(route('dashboard'))
        ->assertSee('lang="en" dir="ltr"', false);
});

it('shares locale, direction and translations with the page', function () {
    $this->withUnencryptedCookie('locale', 'en')
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('dir', 'ltr')
            ->where('locales', ['ar', 'en'])
            ->where('translations', fn ($translations) => $translations['nav.dashboard'] === 'Dashboard'));
});

it('serves Arabic translations by default', function () {
    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'ar')
            ->where('dir', 'rtl')
            ->where('translations', fn ($translations) => $translations['nav.dashboard'] === 'لوحة التحكم'));
});

it('stores the chosen language in a cookie', function () {
    $this->put(route('locale.update'), ['locale' => 'en'])
        ->assertRedirect()
        ->assertCookie('locale', 'en', encrypted: false);
});

it('saves the chosen language on the signed-in user', function () {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)->put(route('locale.update'), ['locale' => 'en']);

    expect($user->fresh()->locale)->toBe('en');
});

it('rejects an unsupported language', function () {
    $this->put(route('locale.update'), ['locale' => 'fr'])
        ->assertSessionHasErrors('locale');
});

it('keeps Arabic and English translation files in sync', function () {
    $en = json_decode(file_get_contents(lang_path('en.json')), true);
    $ar = json_decode(file_get_contents(lang_path('ar.json')), true);

    expect(array_diff_key($en, $ar))->toBe([])
        ->and(array_diff_key($ar, $en))->toBe([]);
});
