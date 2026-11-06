<?php

use App\Integrations\PublicHost;
use App\Operations\Upgrade\Preflight;
use App\Portal\CustomerOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

/*
| Pre-launch audit H-1: the published dev environment runs as APP_ENV=development (scripts/sync-app-env.sh), so every
| guard tied to local/testing works there exactly as in production: the fixed demo code, the SMTP SSRF guard and
| TrustHosts. ops:preflight refuses a public APP_URL left on APP_ENV=local or APP_DEBUG=true.
*/

uses(RefreshDatabase::class);

afterEach(function () {
    // TrustHosts sets a process-wide Symfony list; clear it so later tests see any Host again.
    Request::setTrustedHosts([]);
});

it('ignores the fixed portal demo code in development', function () {
    config(['portal.demo_otp' => '424242']);

    app()->detectEnvironment(fn () => 'local');
    expect(CustomerOtp::demoCode())->toBe('424242');

    app()->detectEnvironment(fn () => 'development');
    expect(CustomerOtp::demoCode())->toBeNull();
});

it('keeps the SMTP SSRF guard on in development', function () {
    app()->detectEnvironment(fn () => 'local');
    expect(app(PublicHost::class)->allows('10.0.0.1'))->toBeTrue();

    app()->detectEnvironment(fn () => 'development');
    $guard = app(PublicHost::class);
    expect($guard->allows('10.0.0.1'))->toBeFalse()
        ->and($guard->allows('169.254.169.254'))->toBeFalse()
        ->and($guard->allows('127.0.0.1'))->toBeFalse()
        ->and($guard->allows('93.184.216.34'))->toBeTrue();
});

it('applies TrustHosts in development, so a forged Host header never reaches a generated link', function () {
    config(['app.url' => 'https://dev.opsone.example']);
    app()->detectEnvironment(fn () => 'development');

    $this->get('https://evil.example/up')->assertBadRequest();
    $this->get('https://dev.opsone.example/up')->assertOk();
    $this->get('https://tenant.dev.opsone.example/up')->assertOk();
});

it('fails preflight when a public APP_URL runs as local or with debug on', function () {
    config(['app.url' => 'https://dev.opsone.example', 'app.debug' => true]);
    app()->detectEnvironment(fn () => 'local');

    $check = collect(app(Preflight::class)->run(true))->firstWhere('name', 'exposure');
    expect($check->result)->toBe('fail')
        ->and($check->message)->toContain('APP_ENV=local')
        ->and($check->message)->toContain('APP_DEBUG=true');

    config(['app.debug' => false]);
    app()->detectEnvironment(fn () => 'development');
    expect(collect(app(Preflight::class)->run(true))->firstWhere('name', 'exposure')->result)->toBe('pass');

    config(['app.debug' => true]);
    expect(collect(app(Preflight::class)->run(true))->firstWhere('name', 'exposure')->result)->toBe('fail');

    // A developer machine may keep both.
    config(['app.url' => 'http://localhost:8080']);
    app()->detectEnvironment(fn () => 'local');
    expect(collect(app(Preflight::class)->run(true))->firstWhere('name', 'exposure')->result)->toBe('pass');
});
