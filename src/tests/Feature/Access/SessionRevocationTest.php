<?php

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M22Membership\T01DisableMembership;
use App\Actions\StateMachines\M22Membership\T02ReactivateMembership;
use App\Actions\StateMachines\M22Membership\T03UpdateMemberAccess;
use App\Enums\MembershipStatus;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->member = $this->org->member('sales', [$this->org->a]);
});

it('denies every action of a disabled member and revokes the open session', function () {
    $this->actingAs($this->member)->get(route('dashboard'))->assertOk();
    $rememberToken = $this->member->refresh()->remember_token;

    app(T01DisableMembership::class)->handle($this->org->owner, Org::membershipOf($this->member), 'left');

    expect(Access::can($this->member, 'P-09', $this->org->a))->toBeFalse()
        ->and(Access::can($this->member, 'P-84', $this->member))->toBeFalse()
        ->and($this->member->refresh()->remember_token)->not->toBe($rememberToken);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    $log = StateTransition::query()->where('machine', 'M-22')->where('transition', 'T01')->sole();
    expect($log->from_state)->toBe('active')
        ->and($log->to_state)->toBe('disabled')
        ->and($log->actor_user_id)->toBe($this->org->owner->id)
        ->and($log->reason)->toBe('left');
});

it('refuses password sign-in for a disabled member', function () {
    app(T01DisableMembership::class)->handle($this->org->owner, Org::membershipOf($this->member), 'left');

    $this->post(route('login.store'), ['email' => $this->member->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('revokes open sessions when the member grants change', function () {
    $this->actingAs($this->member)->get(route('dashboard'))->assertOk();

    app(T03UpdateMemberAccess::class)->handle($this->org->owner, Org::membershipOf($this->member), [
        ['role_template_id' => Org::templateId('executor')],
    ]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
    expect(Access::can($this->member, 'P-09', $this->org->a))->toBeFalse();
});

it('applies grant changes immediately inside the same request', function () {
    expect(Access::can($this->member, 'P-09', $this->org->a))->toBeTrue();

    app(T03UpdateMemberAccess::class)->handle($this->org->owner, Org::membershipOf($this->member), [
        ['role_template_id' => Org::templateId('sales')],
    ], [
        ['permission' => 'P-09', 'effect' => 'deny'],
        ['permission' => 'P-24', 'effect' => 'allow', 'scope' => 'assigned'],
    ]);

    expect(Access::can($this->member, 'P-09', $this->org->a))->toBeFalse();
});

it('keeps sessions of other members untouched', function () {
    $other = $this->org->member('sales', [$this->org->a]);
    $this->actingAs($other)->get(route('dashboard'))->assertOk();

    app(T01DisableMembership::class)->handle($this->org->owner, Org::membershipOf($this->member), 'left');

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($other);
});

it('reactivates a disabled member and rejects invalid M-22 transitions without effect', function () {
    $membership = Org::membershipOf($this->member);

    expect(fn () => app(T02ReactivateMembership::class)->handle($this->org->owner, $membership))
        ->toThrow(InvalidTransition::class);

    app(T01DisableMembership::class)->handle($this->org->owner, $membership, 'leave');
    expect(fn () => app(T01DisableMembership::class)->handle($this->org->owner, $membership, 'again'))
        ->toThrow(InvalidTransition::class);

    app(T02ReactivateMembership::class)->handle($this->org->owner, $membership);

    expect(Org::membershipOf($this->member)->status)->toBe(MembershipStatus::Active)
        ->and(StateTransition::query()->where('machine', 'M-22')->where('subject_id', $membership->id)->pluck('transition')->all())
        ->toBe(['T00', 'T01', 'T02']);
});

it('integrates P-xx codes with the Laravel Gate and can middleware', function () {
    Route::middleware(['web', 'auth', 'can:P-01'])->get('/_test/p01', fn () => 'ok');
    $manager = $this->org->member('manager', [$this->org->a]);

    expect($manager->can('P-03', $this->org->a))->toBeTrue()
        ->and($manager->can('P-03', $this->org->c))->toBeFalse()
        ->and($this->member->can('P-03', $this->org->a))->toBeFalse();

    $this->actingAs($manager)->get('/_test/p01')->assertOk();
    $this->actingAs($this->member)->get('/_test/p01')->assertForbidden();
});
