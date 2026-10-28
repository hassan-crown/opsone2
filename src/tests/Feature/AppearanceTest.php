<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the explicit theme from the appearance cookie', function (string $mode, string $theme, bool $dark) {
    $response = $this->withUnencryptedCookie('appearance', $mode)->get('/login');

    $response->assertSee('data-theme="'.$theme.'"', false);

    $dark
        ? $response->assertSee('class="dark"', false)
        : $response->assertDontSee('class="dark"', false);
})->with([
    'light' => ['light', 'light', false],
    'dark' => ['dark', 'dark', true],
    'contrast' => ['contrast', 'contrast', false],
]);

it('starts from light for system and lets the inline script resolve it', function () {
    $this->withUnencryptedCookie('appearance', 'system')
        ->get('/login')
        ->assertSee('data-theme="light"', false)
        ->assertSee("var appearance = 'system';", false);
});

it('falls back to system for an unknown appearance value', function () {
    $this->withUnencryptedCookie('appearance', '<script>')
        ->get('/login')
        ->assertSee("var appearance = 'system';", false)
        ->assertDontSee('<script>alert', false);
});

it('uses the panel type scale by default', function () {
    $this->get('/login')->assertSee('data-surface="panel"', false);
});
