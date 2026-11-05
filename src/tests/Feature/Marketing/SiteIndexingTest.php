<?php

/**
 * ADR-0045 batch 1: only the published public surface is indexable, only where indexing is on (production by default);
 * the staff panel, customer workspace, cart and checkout always say noindex; robots.txt closes dev/stage; 404 and 410
 * under /ar and /en use the facility's branded page.
 */

use App\Actions\Site\SitePageWriter;
use App\Models\Facility;
use App\Site\Seo\SiteIndexing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../Store/StoreTestHelpers.php';
require_once __DIR__.'/../Site/SiteTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->s = storeFixture(5);
    $this->owner = $this->s->f->org->owner;
    Facility::current()->forceFill(['name_ar' => 'مطابخ النور', 'name_en' => 'Alnoor Kitchens'])->save();
    makePage($this->owner);
});

it('lets search engines index the published site and store, and nothing else', function () {
    $product = $this->s->f->variant->ready_product_id;

    foreach (['/', '/ar', '/en/about', '/ar/store', "/ar/store/p/{$product}", '/sitemap.xml'] as $url) {
        $res = $this->get($url)->assertOk();
        expect($res->headers->get('X-Robots-Tag'))->toBeNull("{$url} must stay indexable");
    }
    $this->get('/en/about')->assertSee('<meta name="robots" content="index, follow">', false);

    foreach (['/ar/store/cart', '/ar/store/checkout', '/portal/login', '/login'] as $url) {
        expect($this->get($url)->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow', $url);
    }
    $this->get('/ar/store/cart')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    expect($this->actingAs($this->owner)->get('/dashboard')->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($this->get('/site-admin/pages')->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($this->get('/store-admin/settings')->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
});

it('marks every named route outside the public list as noindex (no admin route slips through)', function () {
    $public = collect(Route::getRoutes()->getRoutes())->map->getName()->filter()
        ->filter(fn (string $name) => in_array($name, SiteIndexing::PUBLIC_ROUTES, true))->values()->all();

    // Every public route name exists, and none of them is a staff, workspace, cart or checkout route.
    expect($public)->toEqualCanonicalizing(SiteIndexing::PUBLIC_ROUTES);
    foreach ($public as $name) {
        expect($name)->not->toStartWith('site.admin')->not->toStartWith('store.admin')->not->toStartWith('customer.')
            ->not->toContain('cart')->not->toContain('checkout');
    }
});

it('lists the private areas in robots.txt with the sitemap in production', function () {
    $body = $this->get('/robots.txt')->assertOk()->getContent();

    foreach (['/site-admin', '/store-admin', '/portal', '/dashboard', '/field', '/ar/store/cart', '/en/store/checkout'] as $path) {
        expect($body)->toContain("Disallow: {$path}\n");
    }
    expect($body)->toContain('Sitemap: '.rtrim(config('app.url'), '/').'/sitemap.xml')->not->toContain("Disallow: /\n");
});

it('closes dev and stage to crawlers: robots Disallow all, noindex everywhere, a notice in the site admin', function () {
    config(['site.indexing' => false]);

    expect($this->get('/robots.txt')->getContent())->toBe("User-agent: *\nDisallow: /\n");
    $res = $this->get('/en/about')->assertOk();
    expect($res->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($res->getContent())->toContain('<meta name="robots" content="noindex, nofollow">');
    expect($this->get('/ar/store')->getContent())->toContain('content="noindex, nofollow"');

    $this->actingAs($this->owner)->get('/site-admin/pages')->assertInertia(fn ($p) => $p->where('indexing', false));
});

it('follows the environment when SITE_INDEXING is not set', function () {
    config(['site.indexing' => null]);
    expect(SiteIndexing::enabled())->toBeFalse(); // testing

    app()->detectEnvironment(fn () => 'production');
    expect(SiteIndexing::enabled())->toBeTrue();
    app()->detectEnvironment(fn () => 'testing');
});

it('answers unknown public URLs with the branded 404 in the language of the URL', function () {
    $res = $this->get('/ar/no-such-page')->assertNotFound();
    expect($res->getContent())->toContain('<html lang="ar" dir="rtl"')->toContain('data-surface="site"')
        ->toContain(__('site.error.404.title', [], 'ar'))->toContain('مطابخ النور')->toContain('href="/ar"')
        ->and($res->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');

    $this->get('/en/store/p/999999')->assertNotFound()->assertSee(__('site.error.404.title', [], 'en'))->assertSee('Alnoor Kitchens');
    // Outside the public surface the framework's page stays as it was.
    $this->get('/no-such-thing')->assertNotFound()->assertDontSee('data-surface="site"', false);
});

it('answers an archived page with 410 Gone on the branded page, and drops it from the sitemap', function () {
    $page = makePage($this->owner, 'old-offer');
    app(SitePageWriter::class)->archive($this->owner, $page);

    $this->get('/en/old-offer')->assertStatus(410)->assertSee(__('site.error.410.title', [], 'en'));
    $this->get('/sitemap.xml')->assertDontSee('old-offer');
});

it('keeps JSON clients on JSON errors', function () {
    $this->getJson('/en/no-such-page')->assertNotFound()->assertJsonStructure(['message']);
});
