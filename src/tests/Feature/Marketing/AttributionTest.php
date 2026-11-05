<?php

/**
 * ADR-0045 batch 4: the visitor's first / last touch (UTM, click ids, referrer, landing page) is kept in a first-party
 * cookie (Q-79) and written on the lead, appointment request, checkout, customer and order it creates; a converted
 * lead hands it to its order and customer; the «sources and campaigns» report (license `marketing_reports`, Q-77)
 * counts the business per source / medium / campaign, with money only for P-37.
 */

use App\Actions\StateMachines\M03Interest\T02ConvertInterest;
use App\Marketing\Attribution\Attribution;
use App\Marketing\Attribution\TouchClassifier;
use App\Models\Customer;
use App\Models\Interest;
use App\Models\Marketing\MarketingTouch;
use App\Models\Order;
use App\Models\Store\StoreCheckout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;

require_once __DIR__.'/../Store/StoreTestHelpers.php';
require_once __DIR__.'/../Site/SiteTestHelpers.php';
require_once __DIR__.'/../Reports/ReportsTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->s = storeFixture(5);
    $this->owner = $this->s->f->org->owner;
    $this->variant = $this->s->f->variant;
    config(['site.lead.min_seconds' => 3]);
    makePage($this->owner);
});

/** A GET that carries the attribution cookie between requests, like a browser. */
function marketingVisit(object $test, string $url, ?string $referer = null): TestResponse
{
    $response = $test->get($url, $referer === null ? [] : ['Referer' => $referer]);
    if (($cookie = $response->getCookie(Attribution::COOKIE)) !== null) {
        $test->withCookie(Attribution::COOKIE, $cookie->getValue());
    }

    return $response;
}

function submitLead(object $test, string $mobile = '0551234567'): Interest
{
    $submission = (string) Str::uuid();
    $test->post('/ar/contact', [
        'name' => 'سلمان', 'mobile' => $mobile, 'email' => null, 'need' => 'مطبخ جديد',
        'submission' => $submission, 'form_token' => Crypt::encryptString((time() - 30).'|'.$submission), 'website' => '',
    ])->assertRedirect();

    return Interest::query()->latest('id')->firstOrFail();
}

/** @return array<string, array<string, mixed>> */
function touchesOf(Model $record): array
{
    return MarketingTouch::query()->where('touchable_type', $record->getMorphClass())->where('touchable_id', $record->getKey())
        ->get()->mapWithKeys(fn (MarketingTouch $t) => [$t->kind => $t->only(['source', 'medium', 'campaign', 'click_id_type', 'referrer_host', 'landing_path'])])->all();
}

it('classifies a visit: campaign tags, then click ids, then the referring site, else nothing', function () {
    $c = fn (array $q, ?string $ref = null) => TouchClassifier::classify($q, $ref, 'shop.example.sa', '/ar');

    expect($c(['utm_source' => 'Instagram', 'utm_medium' => 'paid_social', 'utm_campaign' => 'eid-2026']))
        ->toMatchArray(['source' => 'instagram', 'medium' => 'paid_social', 'campaign' => 'eid-2026'])
        ->and($c(['gclid' => 'Cj0KCQ.abc-123']))->toMatchArray(['source' => 'google', 'medium' => 'cpc', 'click_id_type' => 'gclid', 'click_id' => 'Cj0KCQ.abc-123'])
        ->and($c(['ttclid' => 'x1']))->toMatchArray(['source' => 'tiktok', 'medium' => 'cpc'])
        ->and($c([], 'https://www.google.com.sa/search?q=kitchens'))->toMatchArray(['source' => 'google', 'medium' => 'organic', 'referrer_host' => 'google.com.sa'])
        ->and($c([], 'https://l.instagram.com/?u=x'))->toMatchArray(['source' => 'instagram', 'medium' => 'social'])
        ->and($c([], 'https://blog.example.org/post'))->toMatchArray(['source' => 'blog.example.org', 'medium' => 'referral'])
        ->and($c([], 'https://shop.example.sa/ar/store'))->toBeNull()   // an inner page of the same site
        ->and($c([]))->toBeNull()
        // Whatever a visitor puts in a URL is cleaned and cut.
        ->and($c(['utm_source' => '<script>x</script>', 'utm_campaign' => str_repeat('a', 400), 'gclid' => 'a"b<c']))
        ->toMatchArray(['source' => 'script x /script', 'click_id' => 'abc'])
        ->and(mb_strlen($c(['utm_source' => 'x', 'utm_campaign' => str_repeat('a', 400)])['campaign']))->toBe(150);
});

it('keeps the first touch and moves the last one on a new campaign; inner pages change nothing', function () {
    $res = marketingVisit($this, '/ar/about?utm_source=instagram&utm_medium=paid_social&utm_campaign=ramadan');
    $cookie = collect($res->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === Attribution::COOKIE);
    expect($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(89)->getTimestamp())
        ->and($cookie->getValue())->not->toContain('instagram'); // encrypted

    marketingVisit($this, '/ar/store');                                       // inner page
    marketingVisit($this, '/ar/store?gclid=abc123');                           // a later Google ad click
    marketingVisit($this, '/ar/about', 'https://shop.example.sa/ar/store');   // inner page again (own host differs in tests: localhost)

    $interest = submitLead($this);
    expect(touchesOf($interest))->toEqual([
        'first' => ['source' => 'instagram', 'medium' => 'paid_social', 'campaign' => 'ramadan', 'click_id_type' => null, 'referrer_host' => null, 'landing_path' => '/ar/about'],
        'last' => ['source' => 'shop.example.sa', 'medium' => 'referral', 'campaign' => null, 'click_id_type' => null, 'referrer_host' => 'shop.example.sa', 'landing_path' => '/ar/about'],
    ]);
});

it('records a direct visit as (direct) / (none), and nothing without a visit cookie', function () {
    marketingVisit($this, '/ar');
    $interest = submitLead($this);
    expect(touchesOf($interest)['first'])->toMatchArray(['source' => '(direct)', 'medium' => '(none)', 'landing_path' => '/ar']);

    $this->flushHeaders();
    $this->defaultCookies = [];
    $bare = submitLead($this, '0557776655');
    expect(touchesOf($bare))->toBe([]);
});

it('writes the touches on the appointment request, the store checkout, its order and the customer', function () {
    marketingVisit($this, '/en/store?utm_source=snapchat&utm_medium=cpc&utm_campaign=summer');
    $buyer = storeBuyer('+966551110000');
    actingAsBuyer($buyer);
    $this->post('/en/store/cart', ['variant_id' => $this->variant->id, 'quantity' => 1]);
    $this->post('/en/store/checkout', ['idempotency_key' => (string) Str::uuid()])->assertRedirect();

    $checkout = StoreCheckout::query()->with('order')->latest('id')->firstOrFail();
    foreach ([$checkout, $checkout->order, $buyer] as $record) {
        expect(touchesOf($record)['last']['campaign'] ?? null)->toBe('summer');
    }
});

it('hands a converted lead’s touches to its order and customer', function () {
    marketingVisit($this, '/ar?utm_source=google&utm_medium=cpc&utm_campaign=kitchens');
    $interest = submitLead($this);

    app(T02ConvertInterest::class)->handle($this->owner, $interest);
    $interest->refresh();
    $order = Order::query()->findOrFail($interest->converted_order_id);
    $customer = Customer::query()->findOrFail($interest->converted_customer_id);

    expect(touchesOf($order)['last']['campaign'])->toBe('kitchens')
        ->and(touchesOf($customer)['first']['source'])->toBe('google');

    $this->actingAs($this->owner)->get(route('interests.show', $interest))
        ->assertInertia(fn (Assert $p) => $p->where('attribution.last.campaign', 'kitchens'));
    $res = $this->get(route('orders.show', $order));
    $res->assertInertia(fn (Assert $p) => $p->where('attribution.first.source', 'google'));
});

it('reports leads, customers, orders and booked sales per source and campaign for the growth plan', function () {
    marketingVisit($this, '/ar?utm_source=instagram&utm_medium=paid_social&utm_campaign=eid');
    submitLead($this, '0551000001');
    submitLead($this, '0551000002');
    $this->withCookie(Attribution::COOKIE, ''); // a new visitor
    marketingVisit($this, '/ar?gclid=zz9');
    $converted = submitLead($this, '0551000003');
    app(T02ConvertInterest::class)->handle($this->owner, $converted);

    $report = repReport($this->owner, 'marketing');
    $metrics = repMetrics($report);
    expect($metrics['marketing.leads'])->toBe(3)
        ->and($metrics['marketing.orders'])->toBe(1)
        ->and($metrics['marketing.new_customers'])->toBe(1);
    $rows = collect($report['table']['rows'])->keyBy('source');
    expect($rows['instagram'])->toMatchArray(['medium' => 'paid_social', 'campaign' => 'eid', 'leads' => 2, 'orders' => 0])
        ->and($rows['google'])->toMatchArray(['medium' => 'cpc', 'leads' => 1, 'orders' => 1, 'customers' => 1])
        ->and(collect($report['table']['columns'])->pluck('key'))->toContain('booked_minor');

    // Without P-37 the booked money is not shown; without the capability the report is not there at all.
    $inventory = $this->s->f->org->member('inventory', [$this->s->f->org->a]);
    $hidden = repReport($inventory, 'marketing');
    expect(collect($hidden['table']['columns'])->pluck('key'))->not->toContain('booked_minor')
        ->and(collect($hidden['metrics'])->pluck('key'))->not->toContain('marketing.booked_minor');
    LicenseFactory::install(['capabilities' => ['ready_goods', 'branch_comparisons']]);
    $this->actingAs($this->owner)->getJson('/reports/marketing')->assertForbidden();
});
