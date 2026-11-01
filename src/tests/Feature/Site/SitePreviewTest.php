<?php

/**
 * THM-04: a private, signed and expiring preview of the draft theme and the draft pages before publishing. The link
 * only sets a cookie in one browser; visitors without it never reach a draft; the preview is read only, noindex and
 * uncached; publishing and rolling back stay consistent with what the preview showed.
 */

use App\Models\Interest;
use App\Models\Site\SitePage;
use App\Models\Site\SiteTheme;
use App\Site\Preview\SitePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\Org;

require_once __DIR__.'/SiteTestHelpers.php';

uses(RefreshDatabase::class);

const PREVIEW_BRAND = '#1D5FA8';

beforeEach(function () {
    Storage::fake(config('attachments.disk'));
    $this->org = Org::make();
    $this->owner = $this->org->owner;
    $this->content = $this->org->member('content', [$this->org->a]);
    $this->sales = $this->org->member('sales', [$this->org->a]);
    $this->page = makePage($this->owner, 'about');
});

/** A browser with nothing: no session, no cookies (the test client keeps none between requests unless told). */
function freshBrowser(): void
{
    auth()->logout();
    test()->flushSession();
    test()->withCookie(SitePreview::COOKIE, ''); // the test client keeps cookies between calls only when told to
}

/** Opens a signed link in a fresh browser and keeps the preview cookie it sets, as a real browser would. */
function openPreview(string $url): void
{
    freshBrowser();
    $response = test()->get($url)->assertRedirect();
    $cookie = $response->getCookie(SitePreview::COOKIE);
    expect($cookie)->not->toBeNull()->and($cookie->getExpiresTime())->toBeGreaterThan(time());
    test()->withCookie(SitePreview::COOKIE, $cookie->getValue());
}

function issueLink(object $t, $user, array $input = []): array
{
    return $t->actingAs($user)->postJson(route('site.admin.preview-link'), $input + ['locale' => 'en', 'target' => 'about'])->assertOk()->json();
}

function draftEverything(object $t): void
{
    $t->actingAs($t->owner)->put(route('site.admin.theme.save'), ['brand_color' => PREVIEW_BRAND])->assertSessionHasNoErrors();
    $t->put(route('site.admin.pages.update', $t->page), ['content' => ['en' => pageContent('About draft', 'Unpublished draft copy')]])->assertSessionHasNoErrors();
}

it('shows the draft page and the draft theme through a signed link, exactly as visitors will see them', function () {
    draftEverything($this);
    $link = issueLink($this, $this->owner);

    openPreview($link['url']);
    $html = $this->get($link['path'])->assertOk()->getContent();

    expect($html)->toContain('Unpublished draft copy')->toContain(PREVIEW_BRAND)->toContain('data-surface="site"')
        ->and($link['theme'])->toBeTrue();
});

it('is noindex, uncached and never leaks a draft to a visitor without the link', function () {
    draftEverything($this);
    $link = issueLink($this, $this->owner);

    openPreview($link['url']);
    $r = $this->get($link['path'])->assertOk();
    expect($r->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($r->headers->get('Cache-Control'))->toContain('no-store')
        ->and($r->getContent())->toContain('name="robots" content="noindex, nofollow"');

    // Another browser: the published copy and the default theme only.
    freshBrowser();
    $public = $this->get('/en/about')->assertOk();
    expect($public->getContent())->not->toContain('Unpublished draft copy')->not->toContain(PREVIEW_BRAND)->toContain('English text')
        ->and($public->headers->get('X-Robots-Tag'))->toBeNull()
        ->and($public->getContent())->toContain('content="index, follow"');
});

it('keeps a never-published draft page private and out of the sitemap', function () {
    $secret = makePage($this->owner, 'secret', publish: false);
    $link = issueLink($this, $this->owner, ['target' => 'secret']);

    freshBrowser();
    $this->get('/en/secret')->assertNotFound();

    openPreview($link['url']);
    $this->get('/en/secret')->assertOk()->assertSee('English text');
    // The sitemap and robots.txt always describe the live site, even in a preview browser.
    expect($this->get('/sitemap.xml')->getContent())->not->toContain('/en/secret');
});

it('refuses a forged, altered or expired link and an unreadable cookie', function () {
    $link = issueLink($this, $this->content); // no P-100: theme=0
    auth()->logout();

    $this->get($link['url'].'&to=/en/store')->assertForbidden();
    $this->get(str_replace('theme=0', 'theme=1', $link['url']))->assertForbidden();
    $this->get(route('site.preview.start', ['to' => '/en/about', 'theme' => 1]))->assertForbidden();

    $this->travel(2)->hours();
    $this->get($link['url'])->assertForbidden();
    $this->travelBack();

    // A cookie that was not issued by the server, and one that already expired, are ignored.
    draftEverything($this);
    auth()->logout();
    $this->flushSession();
    $this->withCookie(SitePreview::COOKIE, 'forged')->get('/en/about')->assertDontSee('Unpublished draft copy');
    freshBrowser();
    $expired = SitePreview::token(true, true, time() - 5);
    $this->withCookie(SitePreview::COOKIE, $expired)->get('/en/about')->assertDontSee('Unpublished draft copy');
});

it('only the open redirect-safe site paths can be targets', function () {
    $this->actingAs($this->owner);
    foreach (['https://evil.test', '//evil.test', '/en/../admin'] as $bad) {
        auth()->logout();
        $url = URL::temporarySignedRoute('site.preview.start', now()->addMinutes(5), ['to' => $bad, 'theme' => 0]);
        $this->get($url)->assertNotFound();
    }
    $this->actingAs($this->owner)->postJson(route('site.admin.preview-link'), ['locale' => 'en', 'target' => 'nope'])->assertNotFound();
    $this->postJson(route('site.admin.preview-link'), ['locale' => 'fr'])->assertUnprocessable();
    $this->postJson(route('site.admin.preview-link'), ['locale' => 'en', 'minutes' => 99999])->assertUnprocessable();
});

it('issuing needs P-55, the draft theme needs P-100, and the issue is audited', function () {
    $this->actingAs($this->sales)->postJson(route('site.admin.preview-link'), ['locale' => 'en'])->assertForbidden();

    draftEverything($this);
    $content = issueLink($this, $this->content);
    expect($content['theme'])->toBeFalse();

    openPreview($content['url']);
    $html = $this->get($content['path'])->assertOk()->getContent();
    expect($html)->toContain('Unpublished draft copy')->not->toContain(PREVIEW_BRAND);

    $owner = issueLink($this, $this->owner);
    expect($owner['theme'])->toBeTrue()
        ->and(DB::table('audit_events')->where('action', 'site_preview.link_issued')->count())->toBe(2);
});

it('is read only: no lead, no write of any kind, from a preview browser', function () {
    draftEverything($this);
    $link = issueLink($this, $this->owner);
    openPreview($link['url']);

    $this->post('/en/contact', ['name' => 'X', 'phone' => '0500000000'])->assertForbidden();
    expect(Interest::query()->count())->toBe(0);
});

it('shows a draft logo only to the preview browser, never publicly', function () {
    $this->actingAs($this->owner)->post(route('site.admin.theme.upload', 'logo'), ['file' => UploadedFile::fake()->image('logo.png', 400, 120)])->assertSessionHasNoErrors();
    $uuid = SiteTheme::current()->draft['logo'];
    $link = issueLink($this, $this->owner);

    auth()->logout();
    $this->get(route('site.media', $uuid))->assertNotFound();

    openPreview($link['url']);
    $this->get($link['path'])->assertSee(route('site.media', $uuid), false);
    $r = $this->get(route('site.media', $uuid))->assertOk();
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
});

it('publish and rollback stay consistent: after apply the preview equals the live site, after restore it shows default', function () {
    draftEverything($this);
    $link = issueLink($this, $this->owner);
    $this->post(route('site.admin.theme.apply'))->assertSessionHasNoErrors();
    $this->post(route('site.admin.pages.publish', $this->page), ['locales' => ['en']])->assertSessionHasNoErrors();

    openPreview($link['url']);
    $preview = $this->get($link['path'])->getContent();
    freshBrowser();
    $live = $this->get('/en/about')->getContent();
    expect($preview)->toContain('Unpublished draft copy')->toContain(PREVIEW_BRAND)
        ->and($live)->toContain('Unpublished draft copy')->toContain(PREVIEW_BRAND);

    // Rollback: restore prepares default Alpha as the draft — the preview shows it, the live site is untouched until applied.
    $this->actingAs($this->owner)->post(route('site.admin.theme.restore'))->assertSessionHasNoErrors();
    openPreview($link['url']);
    expect($this->get($link['path'])->getContent())->not->toContain(PREVIEW_BRAND);
    freshBrowser();
    expect($this->get('/en/about')->getContent())->toContain(PREVIEW_BRAND);
    $this->actingAs($this->owner)->post(route('site.admin.theme.apply'))->assertSessionHasNoErrors();
    auth()->logout();
    expect($this->get('/en/about')->getContent())->not->toContain(PREVIEW_BRAND);
});

it('exit clears the preview', function () {
    draftEverything($this);
    $link = issueLink($this, $this->owner);
    openPreview($link['url']);
    $cleared = $this->get('/site-preview/exit')->assertRedirect('/')->getCookie(SitePreview::COOKIE);
    expect($cleared->getExpiresTime())->toBeLessThan(time());
    freshBrowser();
    $this->get('/en/about')->assertDontSee('Unpublished draft copy');
});
