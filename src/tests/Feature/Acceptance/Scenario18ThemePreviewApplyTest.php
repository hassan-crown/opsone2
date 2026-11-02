<?php

/**
 * Binding scenario 18 «معاينة ثيم وتطبيقها» («اختبارات ترابط ملزمة»): the preview covers the site pages only; applying
 * reflects on the facility-facing surfaces (site, store, customer portal) — never on the staff administration, which keeps
 * OpsOne's identity (Q-03 a, decided by the product owner, ADR-0034) — and never changes a price, an access or a user's
 * own preference.
 *
 * Traces: SRS-THM-04 (preview of the facility's site pages — home, catalogue, product, interest form, cart — on phone and
 * desktop, ar and en, before applying; visitors never see the draft; the admin panel is not shown in the preview),
 * SRS-THM-06 (the theme changes no permission, price, route or ready-goods availability; a user's light/dark/system
 * choice stays theirs), the «where it reflects» table (brand colour: site, store and customer space; the administration
 * keeps OpsOne's identity per Q-03 a); M-35, P-100, P-101, ADR-0021, ADR-0034.
 *
 * Actors over HTTP: the owner (P-100 edit, P-101 apply) on `/site-admin/theme`, a manager and a sales member on the
 * staff screens, visitors on `/{ar|en}` and `/{ar|en}/store`.
 */

use App\Models\Site\SitePage;
use App\Models\Site\SiteTheme;
use App\Models\User;
use App\Site\Preview\SitePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/AcceptancePortalHelpers.php';

const ACC18_BRAND = '#1D5FA8';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('attachments.disk'));
    $this->s = storeFixture(5);
    $this->owner = $this->s->f->org->owner;
    $this->sales = $this->s->f->sales;
    $this->manager = $this->s->f->org->member('manager', [$this->s->f->org->a]);
    $this->variant = $this->s->f->variant;
    $this->page = makePage($this->owner, 'about');
    accPFreshBrowser();
});

/**
 * Facts the theme must never touch: prices, grants, sessions, personal preferences, routes and ready-goods availability.
 *
 * @return array<string, mixed>
 */
function accP18Untouchable(object $t): array
{
    return [
        'prices' => DB::table('catalog_ready_variants')->orderBy('id')->pluck('price_minor', 'id')->all(),
        'grants' => DB::table('member_grants')->count().'/'.DB::table('role_template_grants')->count(),
        'access_versions' => User::query()->orderBy('id')->pluck('access_version', 'id')->all(),
        'locales' => User::query()->orderBy('id')->pluck('locale', 'id')->all(),
        'pages' => SitePage::query()->count().'/'.DB::table('site_page_versions')->count(),
    ];
}

function accP18Html(string $uri, ?string $appearance = null): string
{
    // Cookies stick to the test client: an empty value is «no personal choice» (an unknown value is ignored).
    return (string) test()->withUnencryptedCookie('appearance', $appearance ?? '')->get($uri)->assertOk()->getContent();
}

it('a draft is previewed only by its editor, as site styling: visitors, the store and the administration never see it', function () {
    accPStaff($this->owner);
    $this->put(route('site.admin.theme.save'), ['brand_color' => ACC18_BRAND, 'appearance' => 'dark'])->assertSessionHasNoErrors();
    expect(SiteTheme::current()->state())->toBe('draft')->and(SiteTheme::current()->published)->toBeNull();

    // The editor's preview: the derived site palette in light and dark, without any admin screen.
    $this->get(route('site.admin.theme.edit'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('site/theme')
        ->where('state', 'draft')->where('settings.brand_color', ACC18_BRAND)->has('preview.palette.light')->has('preview.palette.dark'));
    // The administration itself is not restyled by a draft.
    expect(accP18Html('/dashboard'))->not->toContain(ACC18_BRAND);

    accPFreshBrowser();
    expect(accP18Html('/en/about'))->not->toContain(ACC18_BRAND)->not->toContain('--brand:')
        ->and(accP18Html('/ar/store'))->not->toContain(ACC18_BRAND);
    // A staff member without P-100 cannot open the preview.
    accPStaff($this->sales);
    $this->get(route('site.admin.theme.edit'))->assertForbidden();
});

it('the editor previews the facility\'s real site pages and store with the draft before applying (THM-04)', function () {
    accPStaff($this->owner);
    $this->put(route('site.admin.theme.save'), ['brand_color' => ACC18_BRAND])->assertSessionHasNoErrors();

    // A signed, expiring link opens the real pages in a private, noindex preview; the same URLs stay default for visitors.
    $link = $this->postJson(route('site.admin.preview-link'), ['locale' => 'en', 'target' => 'about'])->assertOk()->json();
    accPFreshBrowser();
    $cookie = $this->get($link['url'])->assertRedirect()->getCookie(SitePreview::COOKIE);
    $this->withCookie(SitePreview::COOKIE, $cookie->getValue());

    $preview = $this->get('/en/about')->assertOk();
    expect($preview->getContent())->toContain(ACC18_BRAND)->toContain('noindex')
        ->and(accP18Html('/ar/store'))->toContain(ACC18_BRAND);

    accPFreshBrowser();
    $this->withCookie(SitePreview::COOKIE, '');
    expect(accP18Html('/en/about'))->not->toContain(ACC18_BRAND);
});

it('applying reflects on the site and the store, and changes no price, access, route or personal preference', function () {
    $before = accP18Untouchable($this);
    $salesCan = fn () => [
        test()->get('/orders')->status(), test()->get(route('site.admin.theme.edit'))->status(), test()->get('/store-admin/links')->status(),
    ];
    accPStaff($this->sales);
    $salesBefore = $salesCan();

    accPStaff($this->owner);
    $this->put(route('site.admin.theme.save'), ['brand_color' => ACC18_BRAND, 'appearance' => 'dark'])->assertSessionHasNoErrors();
    // Applying needs P-101: the sales member's request is refused and the live theme stays default.
    accPStaff($this->sales);
    $this->post(route('site.admin.theme.apply'))->assertForbidden();
    expect(SiteTheme::current()->published)->toBeNull();

    accPStaff($this->owner);
    $this->post(route('site.admin.theme.apply'))->assertSessionHasNoErrors();
    expect(SiteTheme::current()->state())->toBe('custom')
        ->and(SiteTheme::current()->published['brand_input'])->toBe(ACC18_BRAND)
        ->and(DB::table('audit_events')->where('action', 'site_theme.applied')->where('actor_user_id', $this->owner->id)->count())->toBe(1);

    // Site pages and the store carry the brand; the facility default (dark) applies only without a personal choice.
    accPFreshBrowser();
    $site = accP18Html('/en/about');
    expect($site)->toContain("[data-surface='site']{--brand:")->toContain('data-theme="dark"')
        ->and(accP18Html('/ar/store'))->toContain(ACC18_BRAND)->toContain('45.00')
        ->and(accP18Html('/en/about', 'light'))->toContain('data-theme="light"')->not->toContain('data-theme="dark"');

    // The administration keeps each user's own appearance (system by default, or their cookie), never the site default.
    accPStaff($this->manager);
    expect(accP18Html('/dashboard'))->toContain("var appearance = 'system';")
        ->and(accP18Html('/dashboard', 'light'))->toContain('data-theme="light"');

    // Nothing else moved: prices, grants, sessions, locales, pages; the same answers for the same member.
    accPStaff($this->sales);
    expect($salesCan())->toBe($salesBefore)
        ->and(accP18Untouchable($this))->toBe($before)
        ->and($this->variant->refresh()->price_minor)->toBe(4500);
    $this->get('/ar/store/p/'.$this->variant->ready_product_id)->assertOk()->assertSee('45.00');
});

it('the applied brand reaches the facility-facing surfaces only; staff screens keep OpsOne\'s identity (Q-03 a, ADR-0034)', function () {
    accPStaff($this->owner);
    $this->put(route('site.admin.theme.save'), ['brand_color' => ACC18_BRAND, 'font' => 'plex-sans'])->assertSessionHasNoErrors();
    $this->post(route('site.admin.theme.apply'))->assertSessionHasNoErrors();

    // Product owner's decision: admin, staff and manager screens always use OpsOne's own logo, colours and fonts.
    foreach ([$this->owner, $this->manager, $this->sales] as $member) {
        accPStaff($member);
        foreach (['/dashboard', '/orders'] as $uri) {
            expect(accP18Html($uri))->not->toContain(ACC18_BRAND)->not->toContain('data-brand="facility"')
                ->not->toContain("[data-brand='facility']")->not->toContain('--font-sans:')->toContain('data-surface="panel"');
        }
    }

    // The customer portal carries the facility's colours (scoped, colours only), as do the site and the store.
    accPCustomer(storeBuyer());
    $portal = accP18Html('/portal');
    expect($portal)->toContain('data-brand="facility"')->toContain("[data-brand='facility']{--brand:")->toContain(ACC18_BRAND)
        ->not->toContain('--font-sans:');
    accPFreshBrowser();
    expect(accP18Html('/en/about'))->toContain(ACC18_BRAND)->toContain('--font-sans:')->and(accP18Html('/ar/store'))->toContain(ACC18_BRAND);
});
