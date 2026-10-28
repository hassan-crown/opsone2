<?php

/**
 * Customer passwordless sign-in (WEB-08, Q-47, ADR-0022): hashed one-time codes, expiry, wrong-code and resend caps,
 * lockout, answers that never reveal whether an account exists, session rotation, a session separate from staff, and
 * sign-out everywhere. Activation link sent by staff (ActivateAccount).
 */

use App\Audit\AuditAction;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\CustomerOtpChallenge;
use App\Models\OutboxMessage;
use App\Notifications\Channels\SmsChannel;
use App\Portal\CustomerOtp;
use App\Portal\PortalInvitation;
use App\Portal\PortalSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = portalFixture();
    Queue::fake();
    $this->customer = $this->f->a->refresh();
});

function otpMessages(): int
{
    return OutboxMessage::query()->where('idempotency_key', 'like', 'portal-otp:%')->count();
}

function smsNotConfigured(): void
{
    app()->instance(SmsChannel::class, new class implements SmsChannel
    {
        public function isConfigured(): bool
        {
            return false;
        }

        public function send(string $to, string $text): void {}
    });
}

it('signs in with a 6-digit code sent by SMS; only its HMAC is stored and the sign-in is audited', function () {
    $this->get('/portal')->assertRedirect('/portal/login');
    $this->get('/portal/login')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/login'));

    // Local number format is accepted and normalized to the account's E.164 mobile.
    $local = '0'.substr($this->customer->mobile, 4);
    $this->post('/portal/login', ['identifier' => $local])->assertRedirect('/portal/login/verify');
    $message = OutboxMessage::query()->where('idempotency_key', 'like', 'portal-otp:%')->sole();
    expect($message->channel->value)->toBe('sms')->and($message->recipient)->toBe($this->customer->mobile);

    $code = lastOtpCode();
    $challenge = CustomerOtpChallenge::query()->sole();
    expect(strlen($code))->toBe(6)
        ->and($challenge->code_hash)->not->toContain($code)
        ->and($challenge->expires_at->diffInMinutes(now(), true))->toBeLessThanOrEqual(5);

    $this->get('/portal/login/verify')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/verify'));
    $this->post('/portal/login/verify', ['code' => $code])->assertRedirect('/portal');

    expect(PortalSession::customer()?->id)->toBe($this->customer->id)
        ->and($this->customer->refresh()->portal_activated_at)->not->toBeNull()
        ->and($challenge->refresh()->consumed_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::PortalSignedIn->value)->count())->toBe(1);
    $this->get('/portal')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/home'));
});

it('uses the account email when the facility has no SMS (Q-47 b) and marks the email verified', function () {
    smsNotConfigured();
    $this->post('/portal/login', ['identifier' => $this->customer->mobile])->assertRedirect('/portal/login/verify');
    $message = OutboxMessage::query()->where('idempotency_key', 'like', 'portal-otp:%')->sole();
    expect($message->channel->value)->toBe('mail')->and($message->recipient)->toBe($this->customer->email);

    $this->post('/portal/login/verify', ['code' => lastOtpCode()])->assertRedirect('/portal');
    expect($this->customer->refresh()->email_verified_at)->not->toBeNull();

    // Without SMS and without an email on the account nothing is sent (never to an address the customer types).
    $this->post('/portal/logout');
    $noEmail = Customer::factory()->create(['branch_id' => $this->f->org->a->id, 'portal_login_enabled' => true, 'email' => null]);
    $this->post('/portal/login', ['identifier' => $noEmail->mobile])->assertRedirect('/portal/login/verify');
    expect(otpMessages())->toBe(1);
});

it('signs in by the email linked to the account, never to another address', function () {
    $this->post('/portal/login', ['identifier' => strtoupper($this->customer->email)])->assertRedirect('/portal/login/verify');
    expect(OutboxMessage::query()->where('idempotency_key', 'like', 'portal-otp:%')->sole()->recipient)->toBe($this->customer->email);

});

it('refuses an expired code', function () {
    $this->post('/portal/login', ['identifier' => $this->customer->mobile]);
    $code = lastOtpCode();
    $this->travel(CustomerOtp::TTL_MINUTES * 60 + 1)->seconds();

    $this->from('/portal/login/verify')->post('/portal/login/verify', ['code' => $code])
        ->assertRedirect('/portal/login/verify')->assertSessionHasErrors(['code' => __('portal.otp.invalid')]);
    expect(PortalSession::customer())->toBeNull();
});

it('burns a code after the wrong-code cap, then locks the identifier for the window', function () {
    $this->post('/portal/login', ['identifier' => $this->customer->mobile]);
    $code = lastOtpCode();
    $wrong = $code === '000000' ? '111111' : '000000';
    foreach (range(1, CustomerOtp::MAX_ATTEMPTS) as $i) {
        $this->post('/portal/login/verify', ['code' => $wrong])->assertSessionHasErrors(['code' => __('portal.otp.invalid')]);
    }
    // The right code no longer works: the code was burned.
    $this->post('/portal/login/verify', ['code' => $code])->assertSessionHasErrors(['code' => __('portal.otp.invalid')]);
    expect(PortalSession::customer())->toBeNull();

    // Keep failing until the per-identifier lock: then even a fresh, right code is refused for the window.
    foreach (range(CustomerOtp::MAX_ATTEMPTS + 2, CustomerOtp::FAILURES_PER_IDENTIFIER) as $i) {
        $this->post('/portal/login/verify', ['code' => $wrong]);
    }
    $this->travel(CustomerOtp::RESEND_COOLDOWN + 1)->seconds();
    $this->post('/portal/login', ['identifier' => $this->customer->mobile])->assertSessionHasErrors('identifier');
    expect(AuditEvent::query()->where('action', AuditAction::PortalSignInLocked->value)->count())->toBe(1);

    $this->travel(CustomerOtp::WINDOW + 1)->seconds();
    portalSignIn($this->customer);
    expect(PortalSession::customer()?->id)->toBe($this->customer->id);
});

it('caps resends: a cooldown between codes and a maximum per identifier in the window; a new code replaces the old', function () {
    $this->post('/portal/login', ['identifier' => $this->customer->mobile])->assertRedirect('/portal/login/verify');
    $first = lastOtpCode();
    $this->post('/portal/login/resend')->assertSessionHasErrors('code');
    expect(otpMessages())->toBe(1);

    foreach (range(2, CustomerOtp::SENDS_PER_IDENTIFIER) as $i) {
        $this->travel(CustomerOtp::RESEND_COOLDOWN + 1)->seconds();
        $this->post('/portal/login/resend')->assertRedirect('/portal/login/verify')->assertSessionHasNoErrors();
    }
    expect(otpMessages())->toBe(CustomerOtp::SENDS_PER_IDENTIFIER);
    $this->travel(CustomerOtp::RESEND_COOLDOWN + 1)->seconds();
    $this->post('/portal/login/resend')->assertSessionHasErrors('code');
    expect(otpMessages())->toBe(CustomerOtp::SENDS_PER_IDENTIFIER);

    // Only the latest code is open.
    $latest = lastOtpCode();
    if ($first !== $latest) {
        $this->post('/portal/login/verify', ['code' => $first])->assertSessionHasErrors('code');
    }
    $this->post('/portal/login/verify', ['code' => $latest])->assertRedirect('/portal');
});

it('caps codes per address across identifiers', function () {
    $others = Customer::factory()->count(CustomerOtp::SENDS_PER_IP)->create(['branch_id' => $this->f->org->a->id, 'portal_login_enabled' => true]);
    foreach ($others as $other) {
        $this->post('/portal/login', ['identifier' => $other->mobile])->assertSessionHasNoErrors();
    }
    $this->post('/portal/login', ['identifier' => $this->customer->mobile])->assertSessionHasErrors('identifier');
    expect(otpMessages())->toBe(CustomerOtp::SENDS_PER_IP);
});

it('answers the same for an unknown, a disabled and a real account (no enumeration)', function () {
    $disabled = Customer::factory()->create(['branch_id' => $this->f->org->a->id, 'portal_login_enabled' => false]);
    $answers = [];
    foreach (['+966512345678', $disabled->mobile, $this->customer->mobile] as $identifier) {
        $response = $this->post('/portal/login', ['identifier' => $identifier]);
        $answers[] = [$response->status(), $response->headers->get('Location'), session()->has('errors')];
        $verify = $this->post('/portal/login/verify', ['code' => '000000']);
        $answers[] = [$verify->status(), $verify->headers->get('Location'), json_encode(session('errors'))];
    }
    expect($answers[0])->toBe($answers[2])->toBe($answers[4])
        ->and($answers[1])->toBe($answers[3])->toBe($answers[5])
        ->and(otpMessages())->toBe(1)
        ->and(CustomerOtpChallenge::query()->count())->toBe(1);
});

it('rotates the session id and CSRF token on sign-in (no fixation)', function () {
    $this->post('/portal/login', ['identifier' => $this->customer->mobile]);
    $before = [session()->getId(), session()->token()];
    $this->post('/portal/login/verify', ['code' => lastOtpCode()])->assertRedirect('/portal');

    expect(session()->getId())->not->toBe($before[0])
        ->and(session()->token())->not->toBe($before[1]);
});

it('keeps customer and staff sessions apart: separate cookie, guard and routes', function () {
    // A customer sign-in writes only the customer cookie and never signs anyone into the staff guard.
    $this->post('/portal/login', ['identifier' => $this->customer->mobile]);
    $response = $this->post('/portal/login/verify', ['code' => lastOtpCode()]);
    $names = array_map(fn ($c) => $c->getName(), $response->headers->getCookies());
    expect($names)->toContain(PortalSession::cookieName())
        ->not->toContain(config('session.cookie'))
        ->and(Auth::guard('web')->check())->toBeFalse()
        ->and(Auth::getDefaultDriver())->toBe('web');

    // A customer reaches no staff route.
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/orders/'.$this->f->orderA->id)->assertRedirect('/login');
    $this->getJson('/orders/'.$this->f->orderA->id)->assertUnauthorized();
});

it('never lets a staff session use customer routes', function () {
    $this->actingAs($this->f->owner);
    $this->get('/portal')->assertRedirect('/portal/login');
    $this->get('/portal/orders/'.$this->f->orderA->id)->assertRedirect('/portal/login');
    $this->getJson('/portal/orders/'.$this->f->orderA->id)->assertUnauthorized();
    $this->post('/portal/documents/'.$this->f->recA->design->id.'/approve', ['content_hash' => $this->f->recA->design->content_hash])->assertRedirect('/portal/login');
    expect($this->f->recA->design->refresh()->status->value)->toBe('awaiting_customer');
});

it('signs out here or everywhere; a disabled login ends the session', function () {
    portalSignIn($this->customer);
    $this->post('/portal/logout')->assertRedirect('/portal/login');
    expect(PortalSession::customer())->toBeNull();
    $this->get('/portal')->assertRedirect('/portal/login');

    // Another device keeps an old stamp: «sign out everywhere» ends it.
    actingAsCustomer($this->customer);
    $this->get('/portal')->assertOk();
    $this->post('/portal/logout/everywhere')->assertRedirect('/portal/login');
    expect($this->customer->refresh()->portal_session_version)->toBe(2);
    Auth::guard(PortalSession::GUARD)->setUser($this->customer);
    session([PortalSession::STAMP => 1]);
    $this->get('/portal')->assertRedirect('/portal/login');
    expect(AuditEvent::query()->where('action', AuditAction::PortalSessionsEnded->value)->count())->toBe(1);

    actingAsCustomer($this->customer);
    $this->get('/portal')->assertOk();
    $this->customer->forceFill(['portal_login_enabled' => false])->save();
    $this->get('/portal')->assertRedirect('/portal/login');
});

it('activates an account from the signed link staff sent; the link alone signs nobody in', function () {
    $this->actingAs($this->f->manager)->post('/customers/'.$this->customer->id.'/portal-invite')->assertRedirect();
    $sms = OutboxMessage::query()->where('idempotency_key', 'like', 'portal-invite:'.$this->customer->id.':%:sms')->sole();
    expect(AuditEvent::query()->where('action', AuditAction::PortalInvited->value)->count())->toBe(1);
    Auth::guard('web')->logout();

    preg_match('#https?://\S+#', (string) $sms->payload['text'], $m);
    $link = $m[0];
    $this->get(parse_url($link, PHP_URL_PATH))->assertForbidden(); // unsigned
    $this->get($link)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/activate')->where('activated', false));
    expect(PortalSession::customer())->toBeNull();

    $this->post($link)->assertRedirect('/portal/login/verify');
    $this->post('/portal/login/verify', ['code' => lastOtpCode()])->assertRedirect('/portal');
    expect($this->customer->refresh()->portal_activated_at)->not->toBeNull()
        ->and(CustomerOtpChallenge::query()->latest('id')->first()->purpose)->toBe(CustomerOtp::ACTIVATION);
});

it('lets only a holder of P-10 over the customer send the link, and only when login is enabled', function () {
    $this->actingAs($this->f->executor)->post('/customers/'.$this->customer->id.'/portal-invite')->assertForbidden();
    $off = Customer::factory()->create(['branch_id' => $this->f->org->a->id, 'portal_login_enabled' => false]);
    $this->actingAs($this->f->manager)->post('/customers/'.$off->id.'/portal-invite')->assertSessionHasErrors('portal');
    expect(OutboxMessage::query()->where('idempotency_key', 'like', 'portal-invite:%')->count())->toBe(0)
        ->and(PortalInvitation::LINK_DAYS)->toBe(7);
});
