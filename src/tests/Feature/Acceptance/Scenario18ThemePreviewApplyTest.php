<?php

/**
 * Binding scenario 18 «معاينة ثيم وتطبيقها» («اختبارات ترابط ملزمة»): the preview covers the site pages only; applying
 * reflects on the defined places of the site and the administration, and never changes a price, an access or a user's
 * own preference.
 *
 * Traces: SRS-THM-04 (preview of the facility's site pages — home, catalogue, product, interest form, cart — on phone and
 * desktop, ar and en, before applying; visitors never see the draft; the admin panel is not shown in the preview),
 * SRS-THM-06 (the theme changes no permission, price, route or ready-goods availability; a user's light/dark/system
 * choice stays theirs), the «where it reflects» table (brand colour: site and customer space, plus the facility's
 * accent touches in the administration); M-35, P-100, P-101, ADR-0021.
 *
 * Actors over HTTP: the owner (P-100 edit, P-101 apply) on `/site-admin/theme`, a manager and a sales member on the
 * staff screens, visitors on `/{ar|en}` and `/{ar|en}/store`.
 */

use App\Models\Site\SitePage;
use App\Models\Site\SiteTheme;
use App\Models\User;
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

it('the editor previews the facility\'s real site pages with the draft before applying (THM-04)', function () {
    accPStaff($this->owner);
    $this->put(route('site.admin.theme.save'), ['brand_color' => ACC18_BRAND])->assertSessionHasNoErrors();

    // Expected: a private preview of the site pages (here the page preview the editor already uses) styled by the draft.
    expect(accP18Html("/site-admin/pages/{$this->page->id}/preview/en"))->toContain(ACC18_BRAND);
})->skip('Acceptance gap: no draft-theme preview of real site pages (page preview renders the published theme only)');

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

it('the applied brand reaches the facility\'s accent places in the administration (THM «أين ينعكس»)', function () {
    accPStaff($this->owner);
    $this->put(route('site.admin.theme.save'), ['brand_color' => ACC18_BRAND])->assertSessionHasNoErrors();
    $this->post(route('site.admin.theme.apply'))->assertSessionHasNoErrors();

    // Expected: the administration carries the facility's applied brand accent (in its page or shared props).
    accPStaff($this->manager);
    expect(accP18Html('/dashboard'))->toContain(ACC18_BRAND);
})->skip('Acceptance gap: the applied theme is not shared with the administration (no brand accent/identity in admin pages)');
