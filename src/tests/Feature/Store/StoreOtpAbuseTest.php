<?php

/**
 * Checkout code abuse limits (pre-launch security audit): a workspace staff turned off is never reopened by a checkout
 * code and the store shares the workspace failure budget (M-3); codes go only to allowed countries and under a
 * store-wide cap whatever the address, with a notice to the owner near the cap (M-4).
 */

use App\Actions\Customers\UpdateCustomer;
use App\Models\Customer;
use App\Models\OutboxMessage;
use App\Models\UserNotification;
use App\Portal\CustomerOtp;
use App\Portal\OtpThrottled;
use App\Portal\PortalSession;
use App\Store\CheckoutOtp;
use App\Store\StoreNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/StoreTestHelpers.php';
require_once __DIR__.'/../Portal/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake(); // outbound messages stay readable (delivery clears the payload)
    $this->s = storeFixture(5);
    $this->variant = $this->s->f->variant;
});

function storeAbuseWrongCode(string $real): string
{
    return $real === '000000' ? '111111' : '000000';
}

it('M-3: a checkout code never reopens a workspace staff turned off, and signs no one in', function () {
    $customer = storeBuyer('+966559998881');
    app(UpdateCustomer::class)->handle($this->s->f->org->owner, $customer, ['portal_login_enabled' => false]);
    expect($customer->refresh()->portal_blocked_at)->not->toBeNull();
    Auth::forgetGuards();

    $this->post('/ar/store/cart', ['variant_id' => $this->variant->id, 'quantity' => 1]);
    $this->post('/ar/store/checkout/code', ['name' => 'Holder', 'mobile' => '0559998881'])->assertSessionHasNoErrors();
    $this->post('/ar/store/checkout/verify', ['code' => storeOtpCode()])->assertSessionHasErrors(['code' => __('checkout.otp.account_blocked')]);

    expect($customer->refresh()->portal_login_enabled)->toBeFalse()
        ->and(Auth::guard(PortalSession::GUARD)->id())->toBeNull();

    // Staff turning it back on lifts the block: the checkout code links the account again.
    app(UpdateCustomer::class)->handle($this->s->f->org->owner, $customer->refresh(), ['portal_login_enabled' => true]);
    expect($customer->refresh()->portal_blocked_at)->toBeNull();
});

it('M-3: wrong checkout codes and wrong sign-in codes count in one failure budget per mobile', function () {
    $customer = storeBuyer('+966559998882');
    $portal = app(CustomerOtp::class);
    $store = app(CheckoutOtp::class);

    // Five wrong sign-in codes from one address, five wrong checkout codes from another.
    $portal->issue($customer->mobile, '10.0.0.1');
    $real = lastOtpCode();
    foreach (range(1, CustomerOtp::MAX_ATTEMPTS) as $i) {
        expect($portal->verify($customer->mobile, storeAbuseWrongCode($real), '10.0.0.1'))->toBeNull();
    }
    $store->issue($customer->mobile, 'Buyer', null, '10.0.0.2');
    $real = storeOtpCode();
    $buyer = ['mobile' => $customer->mobile, 'name' => 'Buyer', 'email' => null];
    foreach (range(1, CustomerOtp::MAX_ATTEMPTS) as $i) {
        expect($store->verify($buyer, storeAbuseWrongCode($real), '10.0.0.2'))->toBeNull();
    }

    // The mobile is now locked in both paths, from any address.
    expect(fn () => $portal->issue($customer->mobile, '10.0.0.3'))->toThrow(OtpThrottled::class)
        ->and(fn () => $store->issue($customer->mobile, 'Buyer', null, '10.0.0.4'))->toThrow(OtpThrottled::class);
});

it('M-4: a checkout code is refused for a mobile outside the allowed countries', function () {
    $this->post('/ar/store/cart', ['variant_id' => $this->variant->id, 'quantity' => 1]);
    $this->post('/ar/store/checkout/code', ['name' => 'Someone', 'mobile' => '+447700900000'])
        ->assertSessionHasErrors(['mobile' => __('checkout.otp.mobile_country')]);

    expect(OutboxMessage::query()->where('idempotency_key', 'like', 'store-otp:%')->count())->toBe(0)
        ->and(Customer::query()->where('mobile', '+447700900000')->exists())->toBeFalse();

    // An allowed list can open another country.
    config(['store.otp.country_codes' => ['966', '44']]);
    expect(CheckoutOtp::allowedCountry('+447700900000'))->toBeTrue();
});

it('M-4: the store-wide cap stops codes whatever the address and tells the owner once near it', function () {
    config(['store.otp.per_hour' => 5, 'store.otp.per_day' => 100]);
    $store = app(CheckoutOtp::class);
    foreach (range(1, 5) as $i) {
        $store->issue('+96655000000'.$i, 'Buyer', null, "10.1.0.{$i}");
    }

    expect(fn () => $store->issue('+966550000009', 'Buyer', null, '10.1.0.99'))->toThrow(OtpThrottled::class)
        ->and(OutboxMessage::query()->where('idempotency_key', 'like', 'store-otp:%')->count())->toBe(5);

    $notices = UserNotification::query()->where('type', StoreNotices::OTP_CAP)->get();
    expect($notices)->toHaveCount(1)
        ->and($notices->sole()->user_id)->toBe($this->s->f->org->owner->id);

    // A new hour opens the cap again.
    $this->travel(3601)->seconds();
    expect($store->issue('+966550000009', 'Buyer', null, '10.1.0.99'))->toBeString();
});
