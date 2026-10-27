<?php

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M01Invitation\T00CreateInvitation;
use App\Actions\StateMachines\M01Invitation\T01AcceptInvitation;
use App\Actions\StateMachines\M01Invitation\T03CancelInvitation;
use App\Actions\StateMachines\M01Invitation\T04RenewInvitation;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\StateTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    [$this->invitation, $this->token] = app(T00CreateInvitation::class)->handle($this->org->owner, [
        'email' => 'invitee@example.com',
        'branch_ids' => [$this->org->a->id],
        'roles' => [['role_template_id' => Org::templateId('sales')]],
    ]);
});

it('expires exactly seven days after creation', function () {
    expect($this->invitation->expires_at->equalTo($this->invitation->created_at->addDays(7)))->toBeTrue();

    $this->travel(7)->days();
    $this->travel(-1)->minutes();
    $this->artisan('access:expire')->assertSuccessful();
    expect($this->invitation->refresh()->status)->toBe(InvitationStatus::Pending);

    $this->travel(1)->minutes();
    $this->artisan('access:expire')->assertSuccessful();
    expect($this->invitation->refresh()->status)->toBe(InvitationStatus::Expired);

    $log = StateTransition::query()->where('machine', 'M-01')->where('transition', 'T02')->sole();
    expect($log->actor_user_id)->toBeNull()->and($log->source)->toBe('system');

    expect(fn () => app(T01AcceptInvitation::class)->handle($this->token, 'Invitee', 'secret-password'))
        ->toThrow(InvalidTransition::class);
    expect(User::query()->where('email', 'invitee@example.com')->exists())->toBeFalse();
});

it('refuses acceptance past expiry even before the expiry job ran', function () {
    $this->travel(7)->days();

    expect(fn () => app(T01AcceptInvitation::class)->handle($this->token, 'Invitee', 'secret-password'))
        ->toThrow(InvalidTransition::class);
    expect(User::query()->where('email', 'invitee@example.com')->exists())->toBeFalse();
});

it('accepts once and creates an active member with the invited role and branches', function () {
    $membership = app(T01AcceptInvitation::class)->handle($this->token, 'Invitee', 'secret-password');

    expect($membership->user->email)->toBe('invitee@example.com')
        ->and($membership->user->email_verified_at)->not->toBeNull()
        ->and($membership->branchIds())->toBe([$this->org->a->id])
        ->and($this->invitation->refresh()->status)->toBe(InvitationStatus::Accepted);

    expect(fn () => app(T01AcceptInvitation::class)->handle($this->token, 'Again', 'secret-password'))
        ->toThrow(InvalidTransition::class);
    expect(Membership::query()->whereHas('user', fn ($q) => $q->where('email', 'invitee@example.com'))->count())->toBe(1);
});

it('re-validates the grant against the inviter at acceptance', function () {
    $manager = $this->org->member('manager', [$this->org->a]);
    [, $token] = app(T00CreateInvitation::class)->handle($manager, [
        'email' => 'second@example.com',
        'branch_ids' => [$this->org->a->id],
        'roles' => [['role_template_id' => Org::templateId('sales')]],
    ]);
    Membership::query()->where('user_id', $manager->id)->update(['status' => 'disabled']);

    expect(fn () => app(T01AcceptInvitation::class)->handle($token, 'Second', 'secret-password'))
        ->toThrow(InvalidTransition::class);
    expect(User::query()->where('email', 'second@example.com')->exists())->toBeFalse();
});

it('renews by cancelling the old link and issuing a new one', function () {
    [$renewed, $newToken] = app(T04RenewInvitation::class)->handle($this->org->owner, $this->invitation);

    expect($this->invitation->refresh()->status)->toBe(InvitationStatus::Cancelled)
        ->and($this->invitation->replaced_by_id)->toBe($renewed->id)
        ->and($renewed->status)->toBe(InvitationStatus::Pending);

    expect(fn () => app(T01AcceptInvitation::class)->handle($this->token, 'Old link', 'secret-password'))
        ->toThrow(InvalidTransition::class);

    app(T01AcceptInvitation::class)->handle($newToken, 'Invitee', 'secret-password');
    expect($renewed->refresh()->status)->toBe(InvitationStatus::Accepted);
});

it('cancels a pending invitation and refuses to cancel twice', function () {
    app(T03CancelInvitation::class)->handle($this->org->owner, $this->invitation);

    expect(fn () => app(T03CancelInvitation::class)->handle($this->org->owner, $this->invitation))
        ->toThrow(InvalidTransition::class);
    expect(fn () => app(T01AcceptInvitation::class)->handle($this->token, 'Invitee', 'secret-password'))
        ->toThrow(InvalidTransition::class);
});

it('allows one pending invitation per email', function () {
    expect(fn () => app(T00CreateInvitation::class)->handle($this->org->owner, [
        'email' => 'INVITEE@example.com',
        'branch_ids' => [$this->org->a->id],
        'roles' => [['role_template_id' => Org::templateId('sales')]],
    ]))->toThrow(InvalidTransition::class);

    expect(Invitation::query()->count())->toBe(1);
});
