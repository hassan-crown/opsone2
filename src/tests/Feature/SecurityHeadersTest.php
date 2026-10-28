<?php

/**
 * App-level security headers on every surface (staff panel, public site, customer workspace), whatever proxy is in front.
 */

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    Org::make();
});

it('forbids framing by other sites and foreign base URLs on the panel, site and workspace', function (string $path) {
    $this->get($path)->assertOk()
        ->assertHeader('Content-Security-Policy', SecurityHeaders::POLICY)
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Strict-Transport-Security');
})->with(['/login', '/', '/ar', '/portal/login']);

it('sends HSTS only over HTTPS (behind the proxy, from the forwarded scheme)', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])->withHeader('X-Forwarded-Proto', 'https')
        ->get('/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});
