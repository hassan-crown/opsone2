<?php

/**
 * Workspace code abuse limits (pre-launch security audit): the demo code opens only the showcase accounts (M-5); a
 * stranger cannot lock a customer out of signing in or signing (L-2); a daily cap of wrong codes per account (L-3);
 * an activation link stops working after the first activation or a staff block (L-9).
 */

use App\Actions\Customers\UpdateCustomer;
use App\Audit\AuditAction;
use App\Models\AuditEvent;
use App\Portal\CustomerOtp;
use App\Portal\OtpThrottled;
use App\Portal\PortalInvitation;
use App\Portal\PortalSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = portalFixture();
    Queue::fake();
    $this->customer = $this->f->a->refresh();
    $this->otp = app(CustomerOtp::class);
});

function portalAbuseWrongCode(): string
{
    return lastOtpCode() === '000000' ? '111111' : '000000';
}

it('M-5: in the demo environment the demo code opens only the showcase customers', function () {
    config(['portal.demo_otp' => '424242']);
    app()->detectEnvironment(fn () => 'demo');

    // A customer outside the showcase data needs the real code.
    $this->otp->issue($this->customer->mobile, '10.0.0.1');
    expect($this->otp->verify($this->customer->mobile, '424242', '10.0.0.1'))->toBeNull();

    // A showcase customer (reserved demo email domain) takes it.
    $this->customer->forceFill(['email' => 'portal1@mail-demo.test'])->save();
    $this->travel(CustomerOtp::RESEND_COOLDOWN + 1)->seconds();
    $this->otp->issue($this->customer->mobile, '10.0.0.1');
    expect($this->otp->verify($this->customer->mobile, '424242', '10.0.0.1')['demo'] ?? null)->toBeTrue();
});

it('M-5: production ignores the demo code even when it is set (regression)', function () {
    config(['portal.demo_otp' => '424242']);
    app()->detectEnvironment(fn () => 'production');
    expect(CustomerOtp::demoCode())->toBeNull()
        ->and(CustomerOtp::isDemoAccount($this->customer))->toBeFalse();
});

it('L-2: wrong codes from another address without a code never lock the customer out', function () {
    foreach (range(1, CustomerOtp::FAILURES_PER_IDENTIFIER) as $i) {
        expect($this->otp->verify($this->customer->mobile, '000000', '10.6.6.6'))->toBeNull();
    }
    expect(fn () => $this->otp->issue($this->customer->mobile, '10.6.6.6'))->toThrow(OtpThrottled::class);

    // The customer, from their own address, still signs in.
    portalSignIn($this->customer);
    expect(PortalSession::customer()?->id)->toBe($this->customer->id);
});

it('L-2: a stranger locking the sign-in does not stop a signed-in customer from getting a signing code', function () {
    // Two codes burned from another address: the mobile is locked for sign-in everywhere.
    foreach (range(1, 2) as $round) {
        $this->otp->issue($this->customer->mobile, '10.6.6.'.$round);
        $wrong = portalAbuseWrongCode();
        foreach (range(1, CustomerOtp::MAX_ATTEMPTS) as $i) {
            $this->otp->verify($this->customer->mobile, $wrong, '10.6.6.'.$round);
        }
        $this->travel(CustomerOtp::RESEND_COOLDOWN + 1)->seconds();
    }
    expect(fn () => $this->otp->issue($this->customer->mobile, '10.0.0.1'))->toThrow(OtpThrottled::class);

    // The step-up keeps its own budget: the signed-in customer gets and uses a signing code.
    $quote = agrSend($this->f->sales, agrQuote($this->f->sales, openCustomOrder($this->f->sales, $this->customer)))->refresh();
    Auth::forgetGuards();
    actingAsCustomer($this->customer);
    $this->post("/portal/documents/{$quote->id}/sign/code")->assertSessionHasNoErrors();
    expect($this->otp->verifyStepUp($this->customer, "document:{$quote->id}", lastOtpCode(), '10.0.0.1'))->not->toBeNull();
});

it('L-3: the 31st wrong code of a day is refused after the window locks have expired, and the lock is audited', function () {
    $ip = 0;
    foreach (range(1, 3) as $window) {
        foreach (range(1, 2) as $code) {
            $ip++;
            $this->otp->issue($this->customer->mobile, "10.3.0.{$ip}");
            $wrong = portalAbuseWrongCode();
            foreach (range(1, CustomerOtp::MAX_ATTEMPTS) as $i) {
                $this->otp->verify($this->customer->mobile, $wrong, "10.3.0.{$ip}");
            }
            $this->travel(CustomerOtp::RESEND_COOLDOWN + 1)->seconds();
        }
        $this->travel(CustomerOtp::WINDOW + 1)->seconds();
    }

    // 30 wrong codes today: every window lock is over, yet the account stays locked for the day.
    expect(fn () => $this->otp->issue($this->customer->mobile, '10.3.1.1'))->toThrow(OtpThrottled::class)
        ->and(fn () => $this->otp->verify($this->customer->mobile, '123456', '10.3.1.1'))->toThrow(OtpThrottled::class)
        ->and(AuditEvent::query()->where('action', AuditAction::PortalSignInLocked->value)->get()
            ->filter(fn (AuditEvent $e) => ($e->after['daily'] ?? false) === true))->toHaveCount(1);

    $this->travel(CustomerOtp::DAY)->seconds();
    portalSignIn($this->customer);
    expect(PortalSession::customer()?->id)->toBe($this->customer->id);
});

it('L-9: the activation link stops working after the first activation', function () {
    $link = PortalInvitation::link($this->customer);
    $this->get($link)->assertOk();

    portalSignIn($this->customer);
    Auth::forgetGuards();
    $this->flushSession();

    $this->get($link)->assertForbidden();
    $this->post($link)->assertForbidden();
    // A new invitation carries the current version.
    $this->get(PortalInvitation::link($this->customer->refresh()))->assertOk();
});

it('L-9: turning the workspace off voids the links already sent, even once it is turned back on', function () {
    $link = PortalInvitation::link($this->customer);
    app(UpdateCustomer::class)->handle($this->f->owner, $this->customer, ['portal_login_enabled' => false]);
    app(UpdateCustomer::class)->handle($this->f->owner, $this->customer->refresh(), ['portal_login_enabled' => true]);
    Auth::forgetGuards();

    $this->get($link)->assertForbidden();
    $this->get(PortalInvitation::link($this->customer->refresh()))->assertOk();
});
