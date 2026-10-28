<?php

/**
 * WEB-01: the public site is server-rendered on its own surface, one URL per language, with SEO tags per language
 * (title, description, canonical, hreflang), a sitemap and robots.txt.
 */

use App\Actions\Site\SitePageWriter;
use App\Models\Facility;
use App\Models\Site\SiteDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Org;

require_once __DIR__.'/SiteTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->facility = Facility::current();
    $this->facility->forceFill(['name_ar' => 'مطابخ النور', 'name_en' => 'Alnoor Kitchens', 'default_locale' => 'ar'])->save();
    makePage($this->org->owner);
});

it('renders the arabic page as RTL on the site surface with its own tags', function () {
    $html = $this->get('/ar/about')->assertOk()->getContent();

    expect($html)->toContain('<html lang="ar" dir="rtl"')
        ->and($html)->toContain('data-surface="site"')
        ->and($html)->toContain('<title>عنا | مطابخ النور</title>')
        ->and($html)->toContain('<meta name="description" content="About عنا">')
        ->and($html)->toContain('<link rel="canonical" href="'.rtrim(config('app.url'), '/').'/ar/about">')
        ->and($html)->toContain('property="og:locale" content="ar_SA"')
        ->and($html)->not->toContain('data-surface="panel"');
});

it('renders the english page as LTR with hreflang alternates for both languages and x-default', function () {
    $html = $this->get('/en/about')->assertOk()->getContent();
    $base = rtrim(config('app.url'), '/');

    expect($html)->toContain('<html lang="en" dir="ltr"')
        ->and($html)->toContain('<title>About | Alnoor Kitchens</title>')
        ->and($html)->toContain('<link rel="canonical" href="'.$base.'/en/about">')
        ->and($html)->toContain('<link rel="alternate" hreflang="ar" href="'.$base.'/ar/about">')
        ->and($html)->toContain('<link rel="alternate" hreflang="en" href="'.$base.'/en/about">')
        ->and($html)->toContain('<link rel="alternate" hreflang="x-default" href="'.$base.'/ar/about">');
});

it('lists hreflang only for languages that are published', function () {
    $writer = app(SitePageWriter::class);
    $page = $writer->create($this->org->owner, 'only-ar', ['ar' => 'عربي']);
    $writer->saveDraft($this->org->owner, $page, ['ar' => pageContent('عربي')]);
    $writer->publish($this->org->owner, $page, ['ar']);

    $html = $this->get('/ar/only-ar')->assertOk()->getContent();

    expect($html)->toContain('hreflang="ar"')->and($html)->not->toContain('<link rel="alternate" hreflang="en"');
    $this->get('/en/only-ar')->assertRedirect('/ar/only-ar');
});

it('serves the home page at / in the default language and at /ar and /en', function () {
    $this->get('/')->assertOk()->assertSee('<html lang="ar" dir="rtl"', false);
    $this->get('/ar')->assertOk()->assertSee('مطابخ النور');
    $this->get('/en')->assertOk()->assertSee('Alnoor Kitchens')->assertSee('hreflang="x-default"', false);

    $this->facility->forceFill(['default_locale' => 'en'])->save();
    $this->get('/')->assertOk()->assertSee('<html lang="en" dir="ltr"', false);
});

it('shows the facility name as text without a logo and a small “by OpsOne” line (Q-49 a)', function () {
    $this->get('/en')->assertSee('Alnoor Kitchens')->assertSee('Powered by OpsOne')->assertDontSee('<img', false);
    $this->get('/ar')->assertSee('بواسطة OpsOne');
});

it('has the accessibility basics: skip link, landmarks, one h1', function () {
    $html = $this->get('/en')->assertOk()->getContent();

    expect($html)->toContain('href="#main"')->and($html)->toContain('<main id="main"')
        ->and($html)->toContain('<header')->and($html)->toContain('<footer')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('generates sitemap.xml with every published language and alternates, and no drafts or archived pages', function () {
    $writer = app(SitePageWriter::class);
    makePage($this->org->owner, 'draft-page', publish: false);
    $archived = makePage($this->org->owner, 'old-page');
    $writer->archive($this->org->owner, $archived);

    $res = $this->get('/sitemap.xml')->assertOk();
    $xml = $res->getContent();
    $base = rtrim(config('app.url'), '/');

    expect($res->headers->get('Content-Type'))->toContain('application/xml')
        ->and($xml)->toContain('<loc>'.$base.'/ar/about</loc>')
        ->and($xml)->toContain('<loc>'.$base.'/en/about</loc>')
        ->and($xml)->toContain('<loc>'.$base.'/ar</loc>')
        ->and($xml)->toContain('hreflang="x-default"')
        ->and($xml)->not->toContain('draft-page')
        ->and($xml)->not->toContain('old-page')
        ->and($xml)->not->toContain('/store')
        ->and(simplexml_load_string($xml))->not->toBeFalse();
});

it('serves a dynamic robots.txt that points at the sitemap and hides the admin', function () {
    $res = $this->get('/robots.txt')->assertOk();

    expect($res->headers->get('Content-Type'))->toContain('text/plain')
        ->and($res->getContent())->toContain('Sitemap: '.rtrim(config('app.url'), '/').'/sitemap.xml')
        ->and($res->getContent())->toContain('Disallow: /site-admin/');
});

it('uses the verified custom domain as canonical origin', function () {
    SiteDomain::query()->create(['hostname' => 'www.alnoor-kitchens.com', 'status' => SiteDomain::VERIFIED, 'verification_token' => 'x']);

    $this->get('/en/about')->assertSee('<link rel="canonical" href="https://www.alnoor-kitchens.com/en/about">', false);
    $this->get('/sitemap.xml')->assertSee('https://www.alnoor-kitchens.com/ar/about', false);
});

it('a pending domain does not change canonical URLs', function () {
    SiteDomain::query()->create(['hostname' => 'pending.example.org', 'status' => SiteDomain::PENDING, 'verification_token' => 'x']);

    $this->get('/en/about')->assertDontSee('pending.example.org');
});

it('draft previews are noindex, need P-55 and never appear to visitors', function () {
    $writer = app(SitePageWriter::class);
    $page = makePage($this->org->owner, 'prev', publish: false);

    $this->get(route('site.admin.pages.preview', [$page, 'en']))->assertRedirect('/login');
    $res = $this->actingAs($this->org->owner)->get(route('site.admin.pages.preview', [$page, 'en']))->assertOk();
    expect($res->getContent())->toContain('noindex')->and($res->getContent())->toContain('English text');

    $sales = $this->org->member('sales', [$this->org->a]);
    $this->actingAs($sales)->get(route('site.admin.pages.preview', [$page, 'en']))->assertForbidden();
    auth()->logout();
    $this->get('/en/prev')->assertNotFound();
});

it('exposes system pages and unknown languages as 404', function () {
    $this->get('/ar/store')->assertNotFound();
    $this->get('/ar/account')->assertNotFound();
    $this->get('/fr/about')->assertNotFound();
});
