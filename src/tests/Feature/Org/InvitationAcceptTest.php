<?php

use App\Actions\Organization\CreateOwnerInvitation;
use App\Actions\StateMachines\M01Invitation\T00CreateInvitation;
use App\Mail\OrgMessage;
use App\Models\Facility;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\RoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

uses(RefreshDatabase::class);

function lastCode(): string
{
    $code = null;
    Mail::assertSent(OrgMessage::class, function (OrgMessage $mail) use (&$code) {
        if (preg_match('/\b(\d{6})\b/', $mail->lines[0], $m)) {
            $code = $m[1];
        }

        return true;
    });

    return (string) $code;
}

describe('owner invitation', function () {
    beforeEach(function () {
        LicenseFactory::install();
        (new RoleTemplateSeeder)->run();
        Facility::query()->create(['name_ar' => 'منشأة', 'name_en' => 'Facility']);
        [$this->invitation, $this->token] = app(CreateOwnerInvitation::class)->handle('owner@example.com', 'Fahad');
        Mail::fake();
    });

    it('shows the valid page, then accepts with the emailed code and becomes the owner', function () {
        $this->get(route('org.owner-invite.show', $this->token))
            ->assertInertia(fn (Assert $p) => $p->component('auth/owner-invite')->where('state', 'valid'));

        $this->post(route('org.owner-invite.code', $this->token))->assertRedirect();
        $code = lastCode();

        $this->post(route('org.owner-invite.accept', $this->token), [
            'code' => $code, 'name' => 'Fahad', 'password' => 'a-long-secret-pass-1', 'password_confirmation' => 'a-long-secret-pass-1',
        ])->assertRedirect(route('org.setup'));

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        expect(Facility::current()->owner_membership_id)->toBe(Membership::query()->where('user_id', $user->id)->value('id'))
            ->and($this->invitation->refresh()->accepted_at)->not->toBeNull();
    });

    it('rejects a wrong code and counts the attempt', function () {
        $this->post(route('org.owner-invite.code', $this->token));
        $this->post(route('org.owner-invite.accept', $this->token), [
            'code' => '000000', 'name' => 'F', 'password' => 'a-long-secret-pass-1', 'password_confirmation' => 'a-long-secret-pass-1',
        ])->assertSessionHasErrors('code');

        expect(User::query()->where('email', 'owner@example.com')->exists())->toBeFalse()
            ->and($this->invitation->refresh()->code_attempts)->toBe(1);
    });

    it('throttles code resend within 45 seconds', function () {
        $this->post(route('org.owner-invite.code', $this->token));
        $this->post(route('org.owner-invite.code', $this->token))->assertSessionHasErrors('code');
    });

    it('refuses reuse and expiry', function () {
        $this->post(route('org.owner-invite.code', $this->token));
        $body = ['code' => lastCode(), 'name' => 'F', 'password' => 'a-long-secret-pass-1', 'password_confirmation' => 'a-long-secret-pass-1'];
        $this->post(route('org.owner-invite.accept', $this->token), $body);
        auth()->logout();

        $this->get(route('org.owner-invite.show', $this->token))->assertInertia(fn (Assert $p) => $p->where('state', 'used'));
        $this->post(route('org.owner-invite.accept', $this->token), $body)->assertSessionHasErrors('token');

        $this->get(route('org.owner-invite.show', 'nope'))->assertInertia(fn (Assert $p) => $p->where('state', 'invalid'));
    });

    it('shows the expired state after seven days', function () {
        $this->travel(7)->days();
        $this->get(route('org.owner-invite.show', $this->token))->assertInertia(fn (Assert $p) => $p->where('state', 'expired'));
    });
});

describe('staff invitation', function () {
    beforeEach(function () {
        $this->org = Org::make();
        [$this->invitation, $this->token] = app(T00CreateInvitation::class)->handle($this->org->owner, [
            'email' => 'new@example.com', 'name' => 'Newbie', 'branch_ids' => [$this->org->a->id],
            'roles' => [['role_template_id' => Org::templateId('sales')]],
        ]);
        Mail::fake();
    });

    it('accepts with the code and signs the new member in', function () {
        $this->get(route('org.invite.show', $this->token))
            ->assertInertia(fn (Assert $p) => $p->component('auth/invite-accept')->where('state', 'valid')->has('invitation.roles', 1));
        $this->post(route('org.invite.code', $this->token));

        $this->post(route('org.invite.accept', $this->token), [
            'code' => lastCode(), 'name' => 'Newbie', 'password' => 'a-long-secret-pass-1', 'password_confirmation' => 'a-long-secret-pass-1',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        expect(Membership::query()->count())->toBe(2)
            ->and($this->invitation->refresh()->status->value)->toBe('accepted');
    });

    it('cannot be accepted without a valid code', function () {
        $this->post(route('org.invite.accept', $this->token), [
            'code' => '123456', 'name' => 'X', 'password' => 'a-long-secret-pass-1', 'password_confirmation' => 'a-long-secret-pass-1',
        ])->assertSessionHasErrors('code');
        expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
    });

    it('reports expired and reused links', function () {
        $this->post(route('org.invite.code', $this->token));
        $body = ['code' => lastCode(), 'name' => 'N', 'password' => 'a-long-secret-pass-1', 'password_confirmation' => 'a-long-secret-pass-1'];
        $this->post(route('org.invite.accept', $this->token), $body);
        auth()->logout();
        $this->get(route('org.invite.show', $this->token))->assertInertia(fn (Assert $p) => $p->where('state', 'used'));

        [, $other] = app(T00CreateInvitation::class)->handle($this->org->owner, [
            'email' => 'late@example.com', 'branch_ids' => [$this->org->a->id],
            'roles' => [['role_template_id' => Org::templateId('sales')]],
        ]);
        $this->travel(7)->days();
        $this->get(route('org.invite.show', $other))->assertInertia(fn (Assert $p) => $p->where('state', 'expired'));
        $this->post(route('org.invite.code', $other))->assertNotFound();
        expect(Invitation::query()->count())->toBe(2);
    });
});
