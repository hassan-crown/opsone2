<?php

/**
 * Coupons (SAL-06/07, M-32), basket links (SAL-01..05, 17, 18, M-31), the license gate (SUB-08..10 facility side,
 * scenario 15) and the store's money rules (coupon before VAT, Q-35 / ADR-0013 rounding).
 */

use App\Access\Exceptions\InvalidTransition;
use App\Enums\ReadyOrderStatus;
use App\Models\Commissions\OrderSale;
use App\Models\Store\Coupon;
use App\Models\Store\CouponUsage;
use App\Models\Store\StoreCheckout;
use App\Models\Store\StoreLink;
use App\Money\VatCalculator;
use App\Store\CheckoutStatus;
use App\Store\CouponAdmin;
use App\Store\CouponUsageStatus;
use App\Store\LinkStatus;
use App\Store\StoreLinks;
use App\Store\StorePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\Support\LicenseFactory;

require_once __DIR__.'/StoreTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = storeFixture(5);
    $this->variant = $this->s->f->variant;
    $this->owner = $this->s->f->org->owner;
    $this->sales = $this->s->f->sales;
    $this->buyer = storeBuyer();
});

function storeCoupon(array $overrides = []): Coupon
{
    return app(CouponAdmin::class)->save(test()->s->f->org->owner, [
        'code' => 'SAVE10', 'title_ar' => 'خصم عشرة', 'kind' => 'percent', 'value' => '10',
        'starts_at' => now()->subHour()->toDateTimeString(), ...$overrides,
    ]);
}

function storeLink(int $quantity = 1, int $hours = 24): array
{
    return app(StoreLinks::class)->issue(test()->s->f->sales, ['lines' => [['variant_id' => test()->s->f->variant->id, 'quantity' => $quantity]], 'hours' => $hours]);
}

it('Q-35 / ADR-0013: the coupon is computed on the eligible value before VAT, then VAT per line half-up', function () {
    $variant = $this->variant->forceFill(['price_minor' => 11500]);
    $coupon = new Coupon(['kind' => Coupon::PERCENT, 'value' => 1000, 'variant_ids' => null]);
    $vat = new VatCalculator(1500, true);

    $priced = StorePricing::price([['variant' => $variant, 'quantity' => 1]], $coupon, $vat);
    // 115.00 inclusive = 100.00 net; 10% of the net = 10.00; VAT on 90.00 = 13.50; total 103.50.
    expect($priced['totals'])->toMatchArray(['subtotal_minor' => 11500, 'discount_minor' => 1000, 'net_minor' => 9000, 'vat_minor' => 1350, 'total_minor' => 10350]);

    $fixed = new Coupon(['kind' => Coupon::AMOUNT, 'value' => 999999, 'variant_ids' => null]);
    expect(StorePricing::price([['variant' => $variant, 'quantity' => 1]], $fixed, $vat)['totals']['total_minor'])->toBe(0);

    // Exclusive prices: the entered amount is the net.
    $ex = StorePricing::price([['variant' => $variant, 'quantity' => 1]], $coupon, new VatCalculator(1500, false));
    expect($ex['totals'])->toMatchArray(['discount_minor' => 1150, 'net_minor' => 10350, 'vat_minor' => 1553, 'total_minor' => 11903]);
});

it('SAL-07: a coupon use is reserved with the attempt, consumed once with the purchase, released by a settled failure', function () {
    $coupon = storeCoupon(['max_uses' => 5]);
    ['checkout' => $paid] = storeStart($this->buyer, [$this->variant->id => 2], 'save10');
    expect($paid->discount_minor)->toBeGreaterThan(0)
        ->and($paid->order->total_minor)->toBe($paid->total_minor)
        ->and(CouponUsage::query()->sole()->status)->toBe(CouponUsageStatus::Reserved)
        ->and($coupon->refresh()->uses_count)->toBe(1);

    $paymentId = FakeMoyasar::pay($paid);
    moyasarWebhook($paymentId)->assertOk();
    moyasarWebhook($paymentId)->assertOk();
    expect(CouponUsage::query()->sole()->status)->toBe(CouponUsageStatus::Consumed)->and($coupon->refresh()->uses_count)->toBe(1);

    ['checkout' => $lapsed] = storeStart(storeBuyer(), [$this->variant->id => 1], 'SAVE10');
    expect($coupon->refresh()->uses_count)->toBe(2);
    $this->travel(31)->minutes();
    Artisan::call('store:expire-checkouts');
    expect($lapsed->couponUsage->refresh()->status)->toBe(CouponUsageStatus::Released)->and($coupon->refresh()->uses_count)->toBe(1);
});

it('SAL-06: dates, pause, minimum, scope and the per-customer cap refuse a coupon', function () {
    storeCoupon(['code' => 'LATER', 'starts_at' => now()->addDay()->toDateTimeString()]);
    $paused = storeCoupon(['code' => 'PAUSED']);
    app(CouponAdmin::class)->pause($this->owner, $paused, true);
    storeCoupon(['code' => 'MIN', 'min_subtotal' => '1000']);
    storeCoupon(['code' => 'ONCE', 'max_uses_per_customer' => 1]);
    $other = makeVariant($this->owner, makeReady($this->owner, 'منتج آخر'), ['code' => 'OTHER-1']);
    storeCoupon(['code' => 'SCOPED', 'variant_ids' => [$other->id]]);

    foreach (['LATER', 'PAUSED', 'MIN', 'SCOPED', 'NOPE'] as $code) {
        expect(fn () => storeStart($this->buyer, [$this->variant->id => 1], $code))->toThrow(ValidationException::class);
    }
    storeStart($this->buyer, [$this->variant->id => 1], 'ONCE');
    expect(fn () => storeStart($this->buyer, [$this->variant->id => 1], 'ONCE'))->toThrow(ValidationException::class)
        ->and(StoreCheckout::query()->count())->toBe(1);
});

it('SAL-06: codes are unique and upper case; values and dates are validated; only P-91 manages coupons', function () {
    storeCoupon(['code' => 'dup-1']);
    expect(Coupon::query()->sole()->code)->toBe('DUP-1');
    expect(fn () => storeCoupon(['code' => 'DUP-1']))->toThrow(ValidationException::class)
        ->and(fn () => storeCoupon(['code' => 'X1', 'value' => '10']))->toThrow(ValidationException::class)
        ->and(fn () => storeCoupon(['code' => 'BIG', 'value' => '100.5']))->toThrow(ValidationException::class)
        ->and(fn () => storeCoupon(['code' => 'BACK', 'ends_at' => now()->subDays(2)->toDateTimeString()]))->toThrow(ValidationException::class);

    $this->actingAs($this->sales)->post('/store-admin/coupons', ['code' => 'SALES', 'title_ar' => 'x', 'kind' => 'percent', 'value' => '5', 'starts_at' => now()->toDateTimeString()])->assertForbidden();
});

it('SAL-02/05: a link is a random token, reserves nothing, and its owner comes only from the server-side record', function () {
    ['link' => $link, 'token' => $token] = storeLink(2);
    expect(strlen($token))->toBe(43)
        ->and($link->status)->toBe(LinkStatus::Active)
        ->and($link->number)->toMatch('/^CL-\d{4}-\d{6}$/')
        ->and(StoreLink::query()->sole()->token_hash)->toBe(hash('sha256', $token))
        ->and(storeAvailable($this->s))->toBe('5');

    // The browser tries to name another owner while issuing: ignored.
    $this->actingAs($this->sales)->post('/store-admin/links', [
        'lines' => [['variant_id' => $this->variant->id, 'quantity' => 1]], 'owner_id' => $this->owner->id, 'hours' => 5,
    ])->assertRedirect();
    expect(StoreLink::query()->latest('id')->first()->owner_id)->toBe($this->sales->id);
    auth()->logout();

    // Buying through the link, with forged owner fields in the request.
    $this->get('/ar/store/l/'.$token)->assertRedirect('/ar/store/checkout');
    $this->get('/ar/store/l/'.substr($token, 0, -1).'x')->assertNotFound();
    actingAsBuyer($this->buyer);
    $response = $this->post('/ar/store/checkout', ['idempotency_key' => 'link-buy-0001', 'owner_id' => $this->owner->id, 'assignee_id' => $this->owner->id, 'link_id' => 999]);
    $response->assertRedirect();

    $checkout = StoreCheckout::query()->sole();
    expect($checkout->link_id)->toBe($link->id)
        ->and($checkout->order->assignee_id)->toBe($this->sales->id)
        ->and($link->refresh()->status)->toBe(LinkStatus::CheckingOut)
        ->and(OrderSale::query()->count())->toBe(0); // opening or starting a payment never makes a sale (SAL-03)

    moyasarWebhook(FakeMoyasar::pay($checkout))->assertOk();
    $sale = OrderSale::query()->sole();
    expect($link->refresh()->status)->toBe(LinkStatus::Used)
        ->and($link->used_order_id)->toBe($checkout->order_id)
        ->and($sale->user_id)->toBe($this->sales->id)
        ->and($sale->owner_source)->toBe('link');

    $this->get('/ar/store/l/'.$token)->assertNotFound(); // used: one purchase only
});

it('SAL-18: a payment made after the link expired is accepted without a sale for the link', function () {
    ['link' => $link] = storeLink(1, 1);
    ['checkout' => $checkout] = storeStart($this->buyer, [], link: $link);
    expect($checkout->link_id)->toBe($link->id);

    moyasarWebhook(FakeMoyasar::pay($checkout, at: now()->addHours(2)->toIso8601String()))->assertOk();

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Paid)
        ->and($link->refresh()->status)->toBe(LinkStatus::Expired)
        ->and(OrderSale::query()->count())->toBe(0);
});

it('SAL-03: a purchase made inside the validity keeps its sale even when the notice arrives late', function () {
    ['link' => $link] = storeLink(1, 1);
    ['checkout' => $checkout] = storeStart($this->buyer, [], link: $link);
    $paidAt = now()->addMinutes(20)->toIso8601String();
    $this->travel(3)->hours();

    moyasarWebhook(FakeMoyasar::pay($checkout, at: $paidAt))->assertOk();
    expect($link->refresh()->status)->toBe(LinkStatus::Used)->and(OrderSale::query()->sole()->user_id)->toBe($this->sales->id);
});

it('M-31: one attempt at a time; a lapsed, cancelled or other customer\'s link refuses; a failure gives it back', function () {
    ['link' => $link] = storeLink();
    ['checkout' => $first] = storeStart($this->buyer, [], link: $link);
    expect(fn () => storeStart(storeBuyer(), [], link: $link))->toThrow(InvalidTransition::class);

    FakeMoyasar::expireInvoice($first);
    $this->travel(31)->minutes();
    Artisan::call('store:expire-checkouts');
    expect($link->refresh()->status)->toBe(LinkStatus::Active);

    app(StoreLinks::class)->cancel($this->sales, $link, 'no longer needed', $link->lock_version);
    expect(fn () => storeStart($this->buyer, [], link: $link->refresh()))->toThrow(InvalidTransition::class);

    ['link' => $bound] = storeLink();
    $bound->forceFill(['customer_id' => storeBuyer()->id])->save();
    expect(fn () => storeStart($this->buyer, [], link: $bound))->toThrow(InvalidTransition::class);
});

it('M-31-T07: replacing a link voids the old token first; cancel needs P-93 over the link', function () {
    ['link' => $link, 'token' => $token] = storeLink();
    $replacement = app(StoreLinks::class)->cancel($this->sales, $link, 'replaced', $link->lock_version, ['lines' => $link->lines]);

    expect($link->refresh()->status)->toBe(LinkStatus::Cancelled)
        ->and($replacement['link']->replaces_id)->toBe($link->id);
    $this->get('/ar/store/l/'.$token)->assertNotFound();
    $this->get('/ar/store/l/'.$replacement['token'])->assertRedirect();

    $stranger = $this->s->f->org->member('executor', [$this->s->f->org->a]);
    $this->actingAs($stranger)->post("/store-admin/links/{$replacement['link']->id}/cancel", ['lock_version' => $replacement['link']->lock_version])->assertForbidden();
});

it('SUB-08 / SAL-17 / scenario 15: removing ready goods hides the store and refuses new checkouts, links and coupons at once; an attempt already started still settles', function () {
    ['checkout' => $started] = storeStart($this->buyer, [$this->variant->id => 1]);
    ['link' => $link, 'token' => $token] = storeLink();

    LicenseFactory::install(['capabilities' => ['branch_comparisons']]);

    $this->get('/ar/store')->assertNotFound();
    $this->get('/ar/store/p/'.$this->variant->ready_product_id)->assertNotFound();
    $this->get('/ar/store/l/'.$token)->assertNotFound();
    expect(fn () => storeStart(storeBuyer(), [$this->variant->id => 1]))->toThrow(InvalidTransition::class)
        ->and(fn () => storeStart(storeBuyer(), [], link: $link))->toThrow(InvalidTransition::class)
        ->and(fn () => storeCoupon(['code' => 'AFTER']))->toThrow(Exception::class)
        ->and(fn () => storeLink())->toThrow(Exception::class);

    moyasarWebhook(FakeMoyasar::pay($started))->assertOk();
    expect($started->refresh()->status)->toBe(CheckoutStatus::Paid)
        ->and($started->order->status)->toBe(ReadyOrderStatus::Confirmed->value);
});

it('SUB-10: restoring the capability reopens the store as it was', function () {
    LicenseFactory::install(['capabilities' => []]);
    $this->get('/ar/store')->assertNotFound();
    // The owner withdraws the pending downgrade: a newer license with the capability again.
    LicenseFactory::install(['capabilities' => ['ready_goods'], 'issued_at' => now()->subHour()->utc()->toIso8601ZuluString()]);
    $this->get('/ar/store')->assertOk();
});
