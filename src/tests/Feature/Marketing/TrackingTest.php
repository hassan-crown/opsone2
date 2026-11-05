<?php

/**
 * ADR-0045 batch 3: marketing settings (P-107, IDs only, audited), the consent-first tag config on public pages, the
 * standard events without personal data, `purchase` sent once, the WhatsApp button, profile links and the policy pages
 * seeded as drafts (Q-80).
 */

use App\Access\Permission;
use App\Actions\Site\SitePageWriter;
use App\Models\AuditEvent;
use App\Models\Marketing\MarketingSettings;
use App\Models\Site\SitePage;
use App\Models\Store\StoreCheckout;
use App\Store\CheckoutStatus;
use App\Store\StoreCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Store/StoreTestHelpers.php';
require_once __DIR__.'/../Site/SiteTestHelpers.php';
require_once __DIR__.'/MarketingTestHelpers.php';

uses(RefreshDatabase::class);

const MARKETING_TAGS = [
    'gtm_id' => 'GTM-AB12CD3', 'ga4_id' => 'G-ABC123DEF4', 'meta_pixel_id' => '123456789012345',
    'tiktok_pixel_id' => 'CABCDEFGH1234567890', 'snap_pixel_id' => '0a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d',
    'google_ads_id' => 'AW-123456789', 'google_ads_purchase_label' => 'PurchaseLbl1', 'google_ads_lead_label' => 'LeadLbl1',
];

beforeEach(function () {
    Queue::fake();
    $this->s = storeFixture(5);
    $this->owner = $this->s->f->org->owner;
    $this->variant = $this->s->f->variant;
    config(['site.lead.min_seconds' => 3]);
});

function saveMarketing(object $test, array $data = []): TestResponse
{
    return $test->actingAs($test->owner)->put('/site-admin/marketing', [...MARKETING_TAGS, ...$data]);
}

/** The tag config a public page embeds, or null. */
function trackingConfig(string $html): ?array
{
    if (preg_match('#<script type="application/json" id="opsone-tracking">(.*?)</script>#s', $html, $m) !== 1) {
        return null;
    }

    return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<string> */
function eventNames(string $html): array
{
    return array_column(trackingConfig($html)['events'] ?? [], 'name');
}

it('lets the owner (P-107) save tag IDs, the WhatsApp button and links, audited, and nobody else', function () {
    $this->actingAs($this->owner)->get('/site-admin/marketing')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('site/marketing')->where('tabs.marketing', true)->where('privacy.published', false));

    saveMarketing($this, [
        'whatsapp_number' => '0551234567', 'whatsapp_message' => ['ar' => 'مرحباً، أريد الاستفسار', 'en' => 'Hello'],
        'links' => ['instagram' => 'https://www.instagram.com/alnoor', 'google_review' => 'https://g.page/r/abc/review'],
    ])->assertRedirect('/site-admin/marketing')->assertSessionHasNoErrors();

    $s = MarketingSettings::current();
    expect($s->gtm_id)->toBe('GTM-AB12CD3')->and($s->whatsapp_number)->toBe('966551234567')
        ->and($s->profiles())->toBe(['instagram' => 'https://www.instagram.com/alnoor'])
        ->and(AuditEvent::query()->where('action', 'marketing_settings.updated')->count())->toBe(1)
        ->and(Permission::P107->label('en'))->toContain('marketing');

    $sales = $this->s->f->sales;
    $this->actingAs($sales)->get('/site-admin/marketing')->assertForbidden();
    $this->actingAs($sales)->put('/site-admin/marketing', MARKETING_TAGS)->assertForbidden();
});

it('accepts only well-formed IDs and https links on the expected hosts — no code can be pasted', function () {
    saveMarketing($this, [
        'gtm_id' => '<script>alert(1)</script>', 'ga4_id' => 'UA-12345-1', 'meta_pixel_id' => '12ab',
        'whatsapp_number' => 'call me', 'links' => ['instagram' => 'javascript:alert(1)', 'x' => 'https://evil.example.com/x'],
    ])->assertSessionHasErrors(['gtm_id', 'ga4_id', 'meta_pixel_id', 'whatsapp_number', 'links.instagram', 'links.x']);

    expect(MarketingSettings::query()->count())->toBe(0);
});

it('embeds no tag config while no tag is set, and never in a draft preview', function () {
    $html = $this->get('/ar/store')->assertOk()->getContent();
    expect(trackingConfig($html))->toBeNull()->and($html)->not->toContain('consent-banner')->not->toContain('googletagmanager');
});

it('embeds the IDs and the page events, with a hidden consent banner and no third-party script in the HTML', function () {
    saveMarketing($this)->assertSessionHasNoErrors();
    auth()->logout();

    $html = $this->get('/en/store')->assertOk()->getContent();
    $config = trackingConfig($html);
    expect($config['tags']['gtm'])->toBe('GTM-AB12CD3')->and($config['tags']['ads_purchase'])->toBe('PurchaseLbl1')
        ->and(eventNames($html))->toBe(['view_item_list'])
        ->and($html)->toContain('<section id="consent-banner" hidden')->toContain('data-consent-open')
        // The platforms are loaded by the build's loader after the consent default, never by a tag in the page.
        ->and($html)->not->toContain('src="https://www.googletagmanager.com')->not->toContain('connect.facebook.net');
});

it('sends view_item, add_to_cart and begin_checkout with the variant code, value and currency', function () {
    saveMarketing($this)->assertSessionHasNoErrors();
    auth()->logout();
    $product = $this->variant->ready_product_id;

    $item = collect(trackingConfig($this->followingRedirects()->get("/en/store/p/{$product}")->getContent())['events'])->firstWhere('name', 'view_item');
    expect($item['params']['currency'])->toBe('SAR')
        ->and($item['params']['items'][0]['item_id'])->toBe($this->variant->code)
        ->and($item['params']['value'])->toBeGreaterThan(0);

    $this->post('/en/store/cart', ['variant_id' => $this->variant->id, 'quantity' => 2])->assertRedirect('/en/store/cart');
    $cart = $this->get('/en/store/cart')->getContent();
    $add = collect(trackingConfig($cart)['events'])->firstWhere('name', 'add_to_cart');
    expect($add['params']['items'][0]['quantity'])->toBe(2)
        ->and($add['params']['value'])->toEqual(2 * $item['params']['value']);
    // A flashed event is sent on the next page only.
    expect(eventNames($this->get('/en/store/cart')->getContent()))->not->toContain('add_to_cart');

    expect(eventNames($this->get('/en/store/checkout')->getContent()))->toContain('begin_checkout');
});

it('sends purchase once per paid checkout, with the order number and no buyer details', function () {
    saveMarketing($this)->assertSessionHasNoErrors();
    auth()->logout();
    $buyer = storeBuyer('+966551112233');
    $buyer->forceFill(['name' => 'سارة المطيري', 'email' => 'sara@example.com'])->save();
    ['checkout' => $checkout] = storeStart($buyer, [$this->variant->id => 2]);
    actingAsBuyer($buyer);

    $pending = "/en/store/checkout/{$checkout->reference}";
    expect(eventNames($this->get($pending)->getContent()))->not->toContain('purchase'); // not paid yet

    moyasarWebhook(FakeMoyasar::pay($checkout))->assertOk();
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Paid);

    $html = $this->get($pending)->getContent();
    $purchase = collect(trackingConfig($html)['events'])->firstWhere('name', 'purchase');
    expect($purchase['id'])->toBe($checkout->number)
        ->and($purchase['params']['transaction_id'])->toBe($checkout->number)
        ->and($purchase['params']['value'])->toEqual((float) ($checkout->total_minor / 100))
        ->and($purchase['params']['items'][0]['quantity'])->toBe(2);
    $json = json_encode(trackingConfig($html), JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('551112233')->not->toContain('سارة')->not->toContain('sara@')->not->toContain('customer')->not->toContain('user_id');

    // Reloads, a second tab, the auto-refresh: never again.
    expect(eventNames($this->get($pending)->getContent()))->not->toContain('purchase')
        ->and(StoreCheckout::query()->whereKey($checkout->id)->value('purchase_tracked_at'))->not->toBeNull();
});

it('sends generate_lead for a real contact request only, without the visitor’s details', function () {
    saveMarketing($this)->assertSessionHasNoErrors();
    auth()->logout();
    makePage($this->owner);
    $submission = (string) Str::uuid();
    $lead = ['name' => 'سلمان العتيبي', 'mobile' => '0559876543', 'email' => 'salman@example.com', 'need' => 'مطبخ',
        'submission' => $submission, 'form_token' => Crypt::encryptString((time() - 30).'|'.$submission), 'website' => ''];

    $this->post('/ar/contact', $lead)->assertRedirect();
    $html = $this->get('/ar/about')->getContent();
    expect(eventNames($html))->toContain('generate_lead')
        ->and($html)->not->toMatch('/opsone-tracking">[^<]*(9876543|salman|سلمان)/u');

    // A bot (honeypot filled) gets the same answer but no conversion.
    $bot = [...$lead, 'submission' => $s = (string) Str::uuid(), 'form_token' => Crypt::encryptString((time() - 30).'|'.$s), 'website' => 'http://spam'];
    $this->post('/ar/contact', $bot)->assertRedirect();
    expect(eventNames($this->get('/ar/about')->getContent()))->not->toContain('generate_lead');
});

it('shows the WhatsApp button, a tracked phone link, profile links and sameAs on every public page', function () {
    $this->s->f->org->facility->forceFill(['contact_phone' => '+966 55 000 0000'])->save();
    saveMarketing($this, [
        'whatsapp_number' => '+966551234567', 'whatsapp_message' => ['ar' => 'مرحباً', 'en' => 'Hi there'],
        'links' => ['instagram' => 'https://instagram.com/alnoor', 'google_business' => 'https://g.page/alnoor'],
    ])->assertSessionHasNoErrors();
    auth()->logout();
    makePage($this->owner);

    foreach (['/en/about', '/en/store'] as $url) {
        $html = $this->get($url)->getContent();
        expect($html)->toContain('href="https://wa.me/966551234567?text=Hi%20there"')->toContain('data-track="click_whatsapp"')
            ->toContain('href="tel:+966550000000" data-track="click_call"')
            ->toContain('href="https://instagram.com/alnoor"');
    }
    $business = collect(jsonLdBlocks($this->get('/en/about')->getContent()))->firstWhere('@type', 'LocalBusiness');
    expect($business['sameAs'])->toBe(['https://instagram.com/alnoor', 'https://g.page/alnoor']);
});

it('seeds the privacy policy and terms as drafts the facility publishes; then they are linked from every page', function () {
    $privacy = SitePage::query()->where('system_key', 'privacy')->firstOrFail();
    expect(SitePage::query()->where('system_key', 'terms')->exists())->toBeTrue()
        ->and($privacy->canArchive())->toBeFalse();
    makePage($this->owner);

    $this->get('/ar/privacy')->assertNotFound(); // nothing public before the facility reviews it
    expect($this->get('/ar/about')->getContent())->not->toContain('href="/ar/privacy"');

    app(SitePageWriter::class)->publish($this->owner, $privacy, ['ar', 'en']);
    $this->get('/ar/privacy')->assertOk()->assertSee('نظام حماية البيانات الشخصية');
    expect($this->get('/ar/about')->getContent())->toContain('href="/ar/privacy"');
    $this->actingAs($this->owner)->get('/site-admin/marketing')->assertInertia(fn (Assert $p) => $p->where('privacy.published', true));
});

it('keeps the store product link working from the sitemap entry it lists', function () {
    // Guard for the feed of batch 2: the path helper and the route agree on encoded Arabic slugs.
    expect(StoreCatalogue::path('ar', 7, 'مقبض-ألمنيوم'))->toBe('/ar/store/p/7/'.rawurlencode('مقبض-ألمنيوم'));
});
