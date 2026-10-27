<?php

use App\Licensing\CurrentLicense;
use App\Licensing\LicenseStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27T09:00:00Z'));
    $this->dir = storage_path('framework/testing/license-'.bin2hex(random_bytes(4)));
    @mkdir($this->dir, 0755, true);
    config(['license.path' => $this->dir.'/license.json']);
});

afterEach(function () {
    array_map(unlink(...), glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

it('issues and installs a development license in testing', function () {
    $this->artisan('license:dev-issue', ['--plan' => 'basic'])->assertSuccessful();

    $license = app(CurrentLicense::class)->refresh();

    expect($license->status)->toBe(LicenseStatus::Active)
        ->and($license->limit('seats'))->toBe(5)
        ->and($license->allows('ready_goods'))->toBeFalse()
        ->and($license->daysRemaining())->toBe(365);
});

it('refuses to issue a development license outside local and testing', function (string $environment) {
    app()->instance('env', $environment);

    $this->artisan('license:dev-issue')
        ->expectsOutputToContain('only runs in the local and testing environments')
        ->assertFailed();

    expect(file_exists((string) config('license.path')))->toBeFalse();
})->with(['production', 'staging', 'dev']);

it('installs a valid license file', function () {
    $source = $this->dir.'/incoming.json';
    file_put_contents($source, LicenseFactory::signed());

    $this->artisan('license:install', ['path' => $source])
        ->expectsOutputToContain('Status: active')
        ->assertSuccessful();

    expect(file_get_contents((string) config('license.path')))->toBe(file_get_contents($source));
});

it('refuses an invalid license file and keeps the installed one', function () {
    LicenseFactory::place();
    $installed = file_get_contents((string) config('license.path'));

    $source = $this->dir.'/incoming.json';
    file_put_contents($source, LicenseFactory::signed(['domains' => ['other.opsone.sa']]));

    $this->artisan('license:install', ['path' => $source])
        ->expectsOutputToContain('domain_mismatch')
        ->assertFailed();

    expect(file_get_contents((string) config('license.path')))->toBe($installed);
});

it('shows the license status', function () {
    LicenseFactory::place();

    $this->artisan('license:status')
        ->expectsOutputToContain('active')
        ->assertSuccessful();
});

it('shares the license banner with signed-in pages without blocking them', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('license.status', 'needs_attention')
            ->where('license.problem', 'missing')
            ->where('license.show', true));
});

it('hides the banner while the license is active and far from renewal', function () {
    LicenseFactory::place();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('license.status', 'active')
            ->where('license.show', false));
});

it('shows the renewal notice in the last 14 days', function () {
    LicenseFactory::place(['ends_at' => '2026-11-05T09:00:00Z']);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('license.status', 'active')
            ->where('license.days_remaining', 9)
            ->where('license.show', true));
});

it('does not share license data with guests', function () {
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('license', null));
});
