<?php

use App\Access\Access;
use App\Access\OwnershipProof;
use App\Access\Permission;
use App\Actions\StateMachines\M01Invitation\T00CreateInvitation;
use App\Licensing\LicenseProblem;
use App\Licensing\LicenseState;
use App\Models\Facility;
use App\Models\OwnershipTransfer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

/*
| Regressions for the P2 security review (organization, account, invitations, ownership transfer, license).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
});

it('does not let a member disable their own membership', function () {
    $manager = $this->org->member('manager', [$this->org->a]);
    $own = Org::membershipOf($manager);
    expect(Access::can($manager, Permission::P06, $own))->toBeTrue();

    $this->actingAs($manager)
        ->post(route('org.team.member.disable', $own), ['reason' => 'oops'])
        ->assertSessionHasErrors('transition');

    expect($own->refresh()->status->value)->toBe('active');
});

it('rate-limits sending invitation emails', function () {
    foreach (['org.team.store', 'org.team.renew'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('throttle:20,1');
    }
});

it('does not reset wrong proof-code attempts when a new code is requested', function () {
    Mail::fake();
    [$invitation] = app(T00CreateInvitation::class)->handle($this->org->owner, [
        'email' => 'invitee@example.com',
        'branch_ids' => [$this->org->a->id],
        'roles' => [['role_template_id' => Org::templateId('sales')]],
    ]);
    $proof = app(OwnershipProof::class);

    for ($i = 0; $i < OwnershipProof::MAX_ATTEMPTS; $i++) {
        $this->travel(OwnershipProof::RESEND_SECONDS + 1)->seconds();
        $proof->send($invitation);
        expect(fn () => $proof->verify($invitation, 'xxxxxx'))->toThrow(ValidationException::class);
    }

    expect($invitation->refresh()->code_attempts)->toBe(OwnershipProof::MAX_ATTEMPTS);

    $this->travel(OwnershipProof::RESEND_SECONDS + 1)->seconds();
    expect(fn () => $proof->send($invitation))->toThrow(ValidationException::class, __('org.accept.code_locked'));
});

it('stops trusting the development key once a production public key is configured', function () {
    config(['license.public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);
    app()->forgetScopedInstances();

    $license = LicenseFactory::placeRaw(LicenseFactory::signed());

    expect($license->problem())->toBe(LicenseProblem::UnknownKey);
});

it('signs out other devices when the password changes but keeps the current one', function () {
    $user = $this->org->member('sales', [$this->org->a]);
    $old = $user->refresh()->access_version;

    $this->actingAs($user)->withSession(['access_version' => $old])
        ->put(route('user-password.update'), [
            'current_password' => 'password', 'password' => 'new-long-password-1', 'password_confirmation' => 'new-long-password-1',
        ])->assertSessionHasNoErrors();

    $new = $user->refresh()->access_version;
    expect($new)->toBeGreaterThan($old)->and(session('access_version'))->toBe($new);
    $this->get(route('dashboard'))->assertOk();

    // Another device still holding the old stamp is signed out.
    $this->flushSession();
    $this->actingAs($user)->withSession(['access_version' => $old])->get(route('dashboard'))->assertRedirect(route('login'));
});

it('ends every session of the account when the password is reset', function () {
    Notification::fake();
    $user = $this->org->member('sales', [$this->org->a]);
    $old = $user->refresh()->access_version;

    $this->post(route('password.email'), ['email' => $user->email]);
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
        $this->post(route('password.update'), [
            'token' => $n->token, 'email' => $user->email, 'password' => 'new-long-password-1', 'password_confirmation' => 'new-long-password-1',
        ])->assertSessionHasNoErrors();

        return true;
    });

    expect($user->refresh()->access_version)->toBeGreaterThan($old);
});

it('answers a reset-link request for an unknown email like a known one, and throttles it', function () {
    Notification::fake();
    $known = $this->org->member('sales', [$this->org->a]);

    $forKnown = $this->from(route('password.request'))->post(route('password.email'), ['email' => $known->email]);
    $forUnknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.com']);

    $forKnown->assertSessionHasNoErrors()->assertSessionHas('status');
    $forUnknown->assertSessionHasNoErrors()->assertSessionHas('status', session('status'));

    // Asking again for the known email (broker-throttled) still looks the same.
    $this->post(route('password.email'), ['email' => $known->email])->assertSessionHasNoErrors();

    foreach (['password.email', 'password.update'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('throttle:password-reset');
    }
});

it('asks the recipient for their password before accepting ownership', function () {
    $recipient = $this->org->member('manager', [$this->org->a]);
    $this->actingAs($this->org->owner)->post(route('ownership.store'), [
        'recipient_id' => Org::membershipOf($recipient)->id, 'current_password' => 'password',
    ])->assertSessionHasNoErrors();
    $transfer = OwnershipTransfer::query()->latest('id')->firstOrFail();

    $this->actingAs($recipient)->post(route('ownership.accept', $transfer))->assertSessionHasErrors('current_password');
    $this->actingAs($recipient)->post(route('ownership.accept', $transfer), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');

    expect($transfer->refresh()->status->value)->toBe('requested')
        ->and(Facility::current()->owner_membership_id)->toBe(Org::membershipOf($this->org->owner)->id);
});

it('treats a license state row with a malformed date as missing instead of failing', function () {
    expect(LicenseState::fromArray(['status' => 'active', 'last_seen_at' => 'not-a-date']))->toBeNull()
        ->and(LicenseState::fromArray(['status' => 'active', 'last_seen_at' => now()->toIso8601String(), 'verified_at' => '???']))->toBeNull();
});
