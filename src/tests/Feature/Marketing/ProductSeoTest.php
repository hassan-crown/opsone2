<?php

/**
 * ADR-0045 batch 2: a ready product is marketable — readable Arabic/English URL (id + slug, 301 to the current one,
 * query kept), 410 once taken off sale, its pictures public while on sale, full search and share tags, schema.org
 * Product / Offer / BreadcrumbList, store entries in the sitemap; site pages get LocalBusiness and FAQPage; a published
 * page that changes its path leaves a 301 behind.
 */

use App\Actions\Site\SitePageWriter;
use App\Catalog\CatalogStatus;
use App\Models\Attachment;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\Facility;
use App\Models\Site\SiteRedirect;
use App\Site\Seo\JsonLd;
use App\Site\Seo\Slug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Store/StoreTestHelpers.php';
require_once __DIR__.'/../Site/SiteTestHelpers.php';

uses(RefreshDatabase::class);

const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

beforeEach(function () {
    Queue::fake();
    $this->s = storeFixture(5);
    $this->owner = $this->s->f->org->owner;
    $this->product = CatalogReadyProduct::query()->findOrFail($this->s->f->variant->ready_product_id);
    Facility::current()->forceFill(['name_ar' => 'مطابخ النور', 'name_en' => 'Alnoor Kitchens', 'contact_phone' => '+966500000000', 'address' => 'الرياض، حي النخيل'])->save();
});

/** Saves the product through the staff screen (P-12) with the given extra fields. */
function saveProduct(object $test, CatalogReadyProduct $product, array $extra = []): TestResponse
{
    return $test->actingAs($test->owner)->put(route('catalog.ready.update', $product), [
        'name' => ['ar' => 'مقبض ألمنيوم', 'en' => 'Aluminium handle'],
        'description' => ['ar' => 'مقبض متين للأبواب والخزائن.', 'en' => 'A sturdy handle for doors and cabinets.'],
        ...$extra,
    ]);
}

/** @return list<array<string, mixed>> */
function jsonLdBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(fn ($j) => json_decode($j, true, flags: JSON_THROW_ON_ERROR), $m[1]);
}

it('makes readable slugs in Arabic and English', function () {
    expect(Slug::make('مقبض ألمنيوم 20 سم'))->toBe('مقبض-ألمنيوم-20-سم')
        ->and(Slug::make('  Aluminium Handle — 20cm! '))->toBe('aluminium-handle-20cm')
        ->and(Slug::make('مَقْبِض ـــ فاخر'))->toBe('مقبض-فاخر')
        ->and(Slug::make('<script>'))->toBe('script')
        ->and(Slug::isValid('مقبض-ألمنيوم'))->toBeTrue()
        ->and(Slug::isValid('Bad Slug'))->toBeFalse()
        ->and(mb_strlen(Slug::make(str_repeat('ب', 200))))->toBe(Slug::MAX);
});

it('serves the product at a readable URL per language and answers 301 from any other slug, keeping campaign tags', function () {
    saveProduct($this, $this->product)->assertSessionHasNoErrors();
    $id = $this->product->id;
    $ar = "/ar/store/p/{$id}/".rawurlencode('مقبض-ألمنيوم');

    $this->get("/ar/store/p/{$id}?utm_source=ig&utm_campaign=eid")->assertStatus(301)->assertRedirect($ar.'?utm_campaign=eid&utm_source=ig'); // both tags kept, normalized order
    $this->get("/en/store/p/{$id}/old-name")->assertStatus(301)->assertRedirect("/en/store/p/{$id}/aluminium-handle");
    $this->get($ar)->assertOk()->assertSee('مقبض ألمنيوم');
    $this->get("/en/store/p/{$id}/aluminium-handle")->assertOk()->assertSee('Aluminium handle');

    // The store list links straight to the readable URL.
    $this->get('/en/store')->assertSee("/en/store/p/{$id}/aluminium-handle", false);
});

it('renders search and share tags with smart defaults, and the editor’s own search fields when set', function () {
    saveProduct($this, $this->product)->assertSessionHasNoErrors();
    $base = rtrim(config('app.url'), '/');
    $id = $this->product->id;
    $html = $this->get("/en/store/p/{$id}/aluminium-handle")->getContent();

    expect($html)->toContain('<title>Aluminium handle | Alnoor Kitchens</title>')
        ->toContain('<meta name="description" content="A sturdy handle for doors and cabinets.">')
        ->toContain('<link rel="canonical" href="'.$base."/en/store/p/{$id}/aluminium-handle\">")
        ->toContain('<link rel="alternate" hreflang="ar" href="'.$base."/ar/store/p/{$id}/".rawurlencode('مقبض-ألمنيوم').'">')
        ->toContain('<link rel="alternate" hreflang="x-default"')
        ->toContain('<meta property="og:type" content="product">')
        ->toContain('<meta property="product:price:amount" content="'.JsonLd::gross(4500).'">')
        ->toContain('<meta property="product:price:currency" content="SAR">')
        ->toContain('<meta name="twitter:card"');

    saveProduct($this, $this->product, [
        'slug' => ['ar' => 'مقبض-فاخر', 'en' => 'premium-handle'],
        'seo_title' => ['ar' => 'مقبض فاخر بسعر خاص', 'en' => 'Premium handle, special price'],
        'seo_description' => ['ar' => 'وصف للبحث', 'en' => 'Search description'],
    ])->assertSessionHasNoErrors();
    $this->get("/en/store/p/{$id}/aluminium-handle")->assertRedirect("/en/store/p/{$id}/premium-handle");
    $this->get("/en/store/p/{$id}/premium-handle")->assertOk()
        ->assertSee('<title>Premium handle, special price | Alnoor Kitchens</title>', false)
        ->assertSee('<meta name="description" content="Search description">', false);
});

it('describes the product to search engines as a Product with its offer and breadcrumbs', function () {
    saveProduct($this, $this->product)->assertSessionHasNoErrors();
    $id = $this->product->id;
    $blocks = collect(jsonLdBlocks($this->get("/en/store/p/{$id}/aluminium-handle")->getContent()))->keyBy('@type');

    expect($blocks->keys()->all())->toContain('LocalBusiness', 'Product', 'BreadcrumbList');
    $product = $blocks['Product'];
    expect($product['name'])->toBe('Aluminium handle')
        ->and($product['sku'])->toBe($this->s->f->variant->code)
        ->and($product['offers']['@type'])->toBe('Offer')
        ->and($product['offers']['price'])->toBe(JsonLd::gross(4500))
        ->and($product['offers']['priceCurrency'])->toBe('SAR')
        ->and($product['offers']['availability'])->toBe('https://schema.org/InStock');
    expect(array_column($blocks['BreadcrumbList']['itemListElement'], 'name'))->toBe(['Alnoor Kitchens', 'Store', 'Aluminium handle'])
        ->and($blocks['LocalBusiness']['telephone'])->toBe('+966500000000')
        ->and($blocks['LocalBusiness']['address']['streetAddress'])->toBe('الرياض، حي النخيل');
});

it('answers 410 for a product taken off sale and 404 for a draft or unknown one', function () {
    $id = $this->product->id;
    $this->product->forceFill(['status' => CatalogStatus::Disabled])->save();
    $this->get("/ar/store/p/{$id}/x")->assertStatus(410)->assertSee(__('site.error.410.title', [], 'ar'));

    $this->product->forceFill(['status' => CatalogStatus::Draft])->save();
    $this->get("/ar/store/p/{$id}/x")->assertNotFound();
    $this->get('/ar/store/p/999999/x')->assertNotFound();
});

it('shows the product pictures publicly only while it is on sale, in the page, the share preview and the sitemap', function () {
    $this->actingAs($this->owner)->post(route('catalog.ready.images', $this->product), [
        'file' => UploadedFile::fake()->createWithContent('handle.png', base64_decode(PNG_1PX)),
    ])->assertRedirect();
    $uuid = Attachment::query()->where('attachable_id', $this->product->id)->where('attachable_type', $this->product->getMorphClass())->value('uuid');
    expect($uuid)->not->toBeNull();
    saveProduct($this, $this->product)->assertSessionHasNoErrors();
    auth()->logout();

    $media = route('site.media', ['attachment' => $uuid]);
    $this->get($media)->assertOk()->assertHeader('Content-Type', 'image/png');
    $html = $this->get("/en/store/p/{$this->product->id}/aluminium-handle")->getContent();
    expect($html)->toContain('<img src="'.$media.'" alt="Aluminium handle"')
        ->toContain('<meta property="og:image" content="'.$media.'">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">');
    $this->get('/en/store')->assertSee($media, false);

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect(simplexml_load_string($xml))->not->toBeFalse()
        ->and($xml)->toContain('/en/store</loc>')
        ->and($xml)->toContain("/en/store/p/{$this->product->id}/aluminium-handle</loc>")
        ->and($xml)->toContain('<image:loc>'.$media.'</image:loc>');

    // A draft product's pictures are private again, and it leaves the sitemap.
    $this->product->forceFill(['status' => CatalogStatus::Draft])->save();
    $this->get($media)->assertNotFound();
    expect($this->get('/sitemap.xml')->getContent())->not->toContain("/store/p/{$this->product->id}/");
});

it('validates the editor’s slug and keeps search fields out of the sold specification versions', function () {
    saveProduct($this, $this->product)->assertSessionHasNoErrors();
    $version = $this->product->refresh()->current_version;

    saveProduct($this, $this->product, ['slug' => ['ar' => 'مقبض ألمنيوم', 'en' => 'Bad/Slug']])
        ->assertSessionHasErrors(['slug.ar', 'slug.en']);
    saveProduct($this, $this->product, ['seo_title' => ['ar' => 'عنوان', 'en' => 'Title']])->assertSessionHasNoErrors();

    expect($this->product->refresh()->current_version)->toBe($version)
        ->and($this->product->seo_title->only('en'))->toBe('Title');

    // Only P-12 holders edit products (the existing catalog rule).
    $this->actingAs($this->s->f->sales)->put(route('catalog.ready.update', $this->product), ['name' => ['ar' => 'x']])->assertForbidden();
});

it('adds LocalBusiness and FAQPage to site pages, never letting text close the script', function () {
    $writer = app(SitePageWriter::class);
    $page = $writer->create($this->owner, 'faq', ['en' => 'FAQ']);
    $writer->saveDraft($this->owner, $page, ['en' => ['title' => 'FAQ', 'sections' => [
        ['id' => 'f', 'type' => 'faq', 'visible' => true, 'data' => ['heading' => 'Questions', 'items' => [
            ['question' => 'Do you deliver?', 'answer' => 'Yes, across Riyadh & Jeddah.'],
        ]]],
    ]]]);
    $writer->publish($this->owner, $page, ['en']);

    $html = $this->get('/en/faq')->assertOk()->getContent();
    $blocks = collect(jsonLdBlocks($html))->keyBy('@type');

    expect($blocks['FAQPage']['mainEntity'][0]['acceptedAnswer']['text'])->toBe('Yes, across Riyadh & Jeddah.')
        ->and($blocks['LocalBusiness']['name'])->toBe('Alnoor Kitchens');

    // Editors cannot type markup (the section rules refuse it); the encoder still escapes <, > and & on its own.
    $encoded = JsonLd::encode(['text' => '</script><script>alert(1)</script>']);
    expect($encoded)->not->toContain('<')->not->toContain('>')
        ->and(json_decode($encoded, true)['text'])->toBe('</script><script>alert(1)</script>');
});

it('keeps old links of a renamed published page working with one 301 hop', function () {
    $writer = app(SitePageWriter::class);
    $page = makePage($this->owner, 'offers');

    $writer->saveDraft($this->owner, $page, [], 'ramadan-offers');
    $writer->saveDraft($this->owner, $page->refresh(), [], 'eid-offers');

    $this->get('/ar/offers')->assertStatus(301)->assertRedirect('/ar/eid-offers');
    $this->get('/en/ramadan-offers')->assertStatus(301)->assertRedirect('/en/eid-offers');
    $this->get('/en/eid-offers')->assertOk();
    expect(SiteRedirect::query()->where('from_path', '/ar/offers')->value('hits'))->toBe(1);

    // A new page that takes an old path is served again instead of redirecting.
    makePage($this->owner, 'offers');
    $this->get('/en/offers')->assertOk();
});
