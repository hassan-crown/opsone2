<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    $this->org = Org::make();
    $this->member = $this->org->member('sales', [$this->org->a]);

    $this->seedSession = fn (int $userId, string $id, string $agent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1') => DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '203.0.113.9',
        'user_agent' => $agent,
        'payload' => '',
        'last_activity' => now()->subMinutes(5)->getTimestamp(),
    ]);
});

it('requires sign in', function () {
    $this->get(route('sessions.index'))->assertRedirect(route('login'));
    $this->delete(route('sessions.destroy-others'))->assertRedirect(route('login'));
});

it('lists my own sessions with device details and never another user\'s', function () {
    ($this->seedSession)($this->member->id, 'phone-session');
    ($this->seedSession)($this->org->owner->id, 'owners-session');

    $this->actingAs($this->member)->get(route('sessions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/sessions')
            ->where('supported', true)
            ->has('sessions', 1)
            ->where('sessions.0.id', 'phone-session')
            ->where('sessions.0.device', 'mobile')
            ->where('sessions.0.platform', 'iOS')
            ->where('sessions.0.browser', 'Safari')
            ->where('sessions.0.ip', '203.0.113.9')
            ->where('sessions.0.current', false));
});

it('reports that listing is unsupported without the database driver', function () {
    config(['session.driver' => 'array']);

    $this->actingAs($this->member)->get(route('sessions.index'))
        ->assertInertia(fn (Assert $page) => $page->where('supported', false)->where('sessions', []));
});

it('signs out the other devices after confirming the password', function () {
    ($this->seedSession)($this->member->id, 'phone-session');
    ($this->seedSession)($this->member->id, 'laptop-session', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0 Safari/537.36');
    ($this->seedSession)($this->org->owner->id, 'owners-session');
    $remember = $this->member->refresh()->remember_token;

    $this->actingAs($this->member)->delete(route('sessions.destroy-others'), ['current_password' => 'password'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('sessions')->whereIn('id', ['phone-session', 'laptop-session'])->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'owners-session')->exists())->toBeTrue()
        ->and($this->member->refresh()->remember_token)->not->toBe($remember);
});

it('keeps the other devices when the password is wrong', function () {
    ($this->seedSession)($this->member->id, 'phone-session');

    $this->actingAs($this->member)->delete(route('sessions.destroy-others'), ['current_password' => 'nope'])
        ->assertSessionHasErrors('current_password');
    $this->actingAs($this->member)->delete(route('sessions.destroy-others'))
        ->assertSessionHasErrors('current_password');

    expect(DB::table('sessions')->where('id', 'phone-session')->exists())->toBeTrue();
});

it('signs out one other device but not someone else\'s', function () {
    ($this->seedSession)($this->member->id, 'phone-session');
    ($this->seedSession)($this->org->owner->id, 'owners-session');

    $this->actingAs($this->member)->delete(route('sessions.destroy', 'owners-session'))->assertNotFound();
    expect(DB::table('sessions')->where('id', 'owners-session')->exists())->toBeTrue();

    $this->actingAs($this->member)->delete(route('sessions.destroy', 'phone-session'))->assertRedirect();
    expect(DB::table('sessions')->where('id', 'phone-session')->exists())->toBeFalse();
});

it('is open to a member whose license is read-only', function () {
    LicenseFactory::install([
        'starts_at' => now()->subDays(400)->toIso8601ZuluString(),
        'ends_at' => now()->subDays(100)->toIso8601ZuluString(),
        'issued_at' => now()->addMinute()->toIso8601ZuluString(),
    ]);

    $this->actingAs($this->member)->get(route('sessions.index'))->assertOk();
});
