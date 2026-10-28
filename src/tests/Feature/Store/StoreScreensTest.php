<?php

/**
 * Store screens over HTTP: StoreList / StoreProduct / StoreCart / Checkout / CheckoutPending on the public site
 * surface (ar + en), the checkout code (Q-48 a), the site showcase, scenario 18 (an applied theme reaches the store
 * without changing a price), customer isolation, and the staff screens (StoreSettings with Moyasar, Coupons, CouponNew,
 * LinksMobile) with their permissions and secrets.
 */

use App\Actions\Site\SitePageWriter;
use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Models\Store\StoreCheckout;
use App\Portal\PortalSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/StoreTestHelpers.php';
require_once __DIR__.'/../Site/SiteTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake(); // outbound messages stay readable (delivery clears the payload)
    $this->s = storeFixture(5);
    $this->variant = $this->s->f->variant;
    $this->owner = $this->s->f->org->owner;
});

it('StoreList, StoreProduct and StoreCart render in both languages on the site surface with availability', function () {
    $product = $this->variant->ready_product_id;

    $this->get('/ar/store')->assertOk()->assertSee('data-surface="site"', false)->assertSee('dir="rtl"', false)
        ->assertSee('مقبض ألمنيوم')->assertSee('45.00')->assertSee(__('store.public.in_stock', [], 'ar'));
    $this->get('/en/store')->assertOk()->assertSee('dir="ltr"', false)->assertSee(__('store.public.title', [], 'en'));
    $this->get("/ar/store/p/{$product}")->assertOk()->assertSee(__('store.public.available', ['count' => 5], 'ar'));
    $this->get('/ar/store/p/999999')->assertNotFound();

    $this->post('/ar/store/cart', ['variant_id' => $this->variant->id, 'quantity' => 2])->assertRedirect('/ar/store/cart');
    $this->get('/ar/store/cart')->assertOk()->assertSee('90.00');
    $this->put("/ar/store/cart/{$this->variant->id}", ['quantity' => 0])->assertRedirect('/ar/store/cart');
    $this->get('/ar/store/checkout')->assertRedirect('/ar/store/cart');
});

it('Q-48 (a): the checkout code creates the buyer\'s account, signs them in and keeps the cart; a wrong code is refused', function () {
    $this->post('/ar/store/cart', ['variant_id' => $this->variant->id, 'quantity' => 1]);
    $this->get('/ar/store/checkout')->assertOk()->assertSee(__('checkout.otp.lead', [], 'ar'));

    $this->post('/ar/store/checkout/code', ['name' => 'سارة', 'mobile' => '0551234567'])->assertRedirect('/ar/store/checkout');
    $this->post('/ar/store/checkout/verify', ['code' => '000000'])->assertSessionHasErrors('code');
    expect(Customer::query()->where('mobile', '+966551234567')->exists())->toBeFalse();

    $this->post('/ar/store/checkout/code', ['name' => 'سارة', 'mobile' => '0551234567']); // cooldown: refused quietly
    $this->post('/ar/store/checkout/verify', ['code' => storeOtpCode()])->assertRedirect('/ar/store/checkout');

    $customer = Customer::query()->where('mobile', '+966551234567')->sole();
    expect($customer->name)->toBe('سارة')
        ->and($customer->portal_login_enabled)->toBeTrue()
        ->and($customer->branch_id)->toBe($this->s->f->org->a->id)
        ->and(Auth::guard(PortalSession::GUARD)->id())->toBe($customer->id);

    $this->get('/ar/store/checkout')->assertOk()->assertSee(__('checkout.signed_in_as', ['name' => 'سارة'], 'ar'));
    $this->post('/ar/store/checkout', ['idempotency_key' => 'http-start-0001'])->assertRedirectContains('https://checkout.moyasar.test/invoices/');
    expect(StoreCheckout::query()->sole()->customer_id)->toBe($customer->id);
    $this->get('/ar/store/cart')->assertSee(__('store.public.cart_empty', [], 'ar'));
});

it('Q-48 (a): an existing customer\'s mobile is linked to that account, never duplicated', function () {
    $existing = storeBuyer('+966559998887');
    $existing->forceFill(['portal_login_enabled' => false])->save();
    $this->post('/ar/store/cart', ['variant_id' => $this->variant->id, 'quantity' => 1]);

    $this->post('/ar/store/checkout/code', ['name' => 'Someone else', 'mobile' => '0559998887']);
    $this->post('/ar/store/checkout/verify', ['code' => storeOtpCode()])->assertRedirect();

    expect(Customer::query()->where('mobile', '+966559998887')->count())->toBe(1)
        ->and($existing->refresh()->name)->not->toBe('Someone else')
        ->and($existing->portal_login_enabled)->toBeTrue()
        ->and(Auth::guard(PortalSession::GUARD)->id())->toBe($existing->id);
});

it('CheckoutPending and the workspace show a store order only to its buyer', function () {
    $a = storeBuyer();
    $b = storeBuyer();
    ['checkout' => $checkout] = storeStart($a, [$this->variant->id => 1]);

    actingAsCustomer($a);
    $this->get("/en/store/checkout/{$checkout->reference}")->assertOk()->assertSee($checkout->order->number)->assertSee('Continue to payment');
    $this->get("/portal/orders/{$checkout->order_id}")->assertOk();

    Auth::forgetGuards();
    actingAsCustomer($b);
    $this->get("/en/store/checkout/{$checkout->reference}")->assertNotFound();
    $this->get("/portal/orders/{$checkout->order_id}")->assertNotFound();
});

it('the site showcase lists the store\'s ready goods while the store sells', function () {
    $writer = app(SitePageWriter::class);
    $page = $writer->create($this->owner, 'shop-window', ['ar' => 'المعرض', 'en' => 'Showcase']);
    $section = fn (string $h) => ['title' => $h, 'meta_description' => $h, 'sections' => [['id' => 'rg', 'type' => 'ready_goods', 'visible' => true, 'data' => ['heading' => $h, 'text' => '']]]];
    $writer->saveDraft($this->owner, $page, ['ar' => $section('منتجاتنا'), 'en' => $section('Our goods')]);
    $writer->publish($this->owner, $page, ['ar', 'en']);
    Auth::forgetGuards();

    $this->get('/ar/shop-window')->assertOk()->assertSee('مقبض ألمنيوم')->assertSee('/ar/store/p/', false);
});

it('scenario 18 (store part): an applied theme reaches the store pages and changes no price', function () {
    $this->actingAs($this->owner)->put(route('site.admin.theme.save'), ['brand_color' => '#1D5FA8'])->assertSessionHasNoErrors();
    $this->post(route('site.admin.theme.apply'))->assertSessionHasNoErrors();
    Auth::forgetGuards();

    $this->get('/ar/store')->assertOk()->assertSee('#1D5FA8', false)->assertSee('45.00');
    expect($this->variant->refresh()->price_minor)->toBe(4500);
});

it('StoreSettings: the owner sets the store and a Moyasar TEST account; live keys are refused and no secret reaches the browser', function () {
    $this->actingAs($this->owner)->get('/store-admin/settings')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('store/settings')->where('open', true)->where('payments.mode', 'test')->missing('payments.secret_key'));

    $this->put('/store-admin/settings/payments', ['publishable_key' => 'pk_live_'.str_repeat('a', 24), 'secret_key' => 'sk_live_'.str_repeat('b', 24), 'webhook_secret' => STORE_WEBHOOK_SECRET, 'enabled' => true])
        ->assertSessionHasErrors('publishable_key');
    $secret = 'sk_test_'.str_repeat('c', 24);
    $this->put('/store-admin/settings/payments', ['publishable_key' => 'pk_test_'.str_repeat('a', 24), 'secret_key' => $secret, 'webhook_secret' => STORE_WEBHOOK_SECRET, 'enabled' => true])
        ->assertSessionHasNoErrors();

    $raw = (string) DB::table('integration_settings')->where('key', 'moyasar')->value('config');
    expect($raw)->not->toContain($secret)
        ->and(IntegrationSetting::query()->where('key', 'moyasar')->sole()->config['secret_key'])->toBe($secret);
    $html = $this->get('/store-admin/settings')->assertOk()->getContent();
    expect($html)->not->toContain($secret)->not->toContain(STORE_WEBHOOK_SECRET);

    $this->put('/store-admin/settings', ['enabled' => false, 'branch_id' => $this->s->f->org->a->id, 'reservation_minutes' => 30, 'link_hours_default' => 24, 'link_days_max' => 7])->assertSessionHasNoErrors();
    Auth::forgetGuards();
    $this->get('/ar/store')->assertNotFound();
});

it('Coupons and CouponNew need P-91; LinksMobile needs P-92 and lists only the member\'s own links', function () {
    $sales = $this->s->f->sales;
    $this->actingAs($this->owner)->get('/store-admin/coupons')->assertOk()->assertInertia(fn (Assert $p) => $p->component('store/coupons'));
    $this->get('/store-admin/coupons/new')->assertOk()->assertInertia(fn (Assert $p) => $p->component('store/coupon-new')->has('variants', 1));
    $this->post('/store-admin/links', ['lines' => [['variant_id' => $this->variant->id, 'quantity' => 1]]])->assertRedirect();

    $this->actingAs($sales)->get('/store-admin/coupons')->assertForbidden();
    $this->get('/store-admin/settings')->assertForbidden();
    $this->get('/store-admin/links')->assertOk()->assertInertia(fn (Assert $p) => $p->component('store/links')->has('links', 0));
    $this->post('/store-admin/links', ['lines' => [['variant_id' => $this->variant->id, 'quantity' => 2]]])->assertRedirect();
    $this->get('/store-admin/links')->assertInertia(fn (Assert $p) => $p->has('links', 1)->where('links.0.lines', 1)->where('links.0.status', 'active'));

    $this->actingAs($this->s->f->org->member('executor', [$this->s->f->org->a]))->get('/store-admin/links')->assertForbidden();
});
