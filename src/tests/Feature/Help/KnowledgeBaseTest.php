<?php

use App\Access\Authorizer;
use App\Access\Contracts\LicenseCapabilities;
use App\Access\Enums\Audience;
use App\Access\LicensedCapabilities;
use App\Access\Permission;
use App\Access\PermissionCatalogue;
use App\Help\HelpCategory;
use App\Help\KnowledgeBase\ArticleLibrary;
use App\Help\KnowledgeBase\FrontMatter;
use App\Models\Customer;
use App\Models\HelpArticle;
use App\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

require_once __DIR__.'/../Portal/PortalTestHelpers.php';

/*
| Knowledge base per role (ADR-0048): Markdown files → help:sync → help_articles, filtered on the server by
| audience, permissions and license capability.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->kbRoot = storage_path('framework/testing/help-'.getmypid().'-'.bin2hex(random_bytes(4)));
});

afterEach(function () {
    File::deleteDirectory($this->kbRoot);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function kbArticle(array $overrides = []): HelpArticle
{
    return HelpArticle::query()->create([
        'slug' => 'kb-sample', 'audience' => 'staff', 'category' => 'daily_work',
        'title_ar' => 'مقال تجريبي', 'summary_ar' => 'ملخص', 'body_ar' => 'نص',
        'published_at' => now()->subDay(),
        ...$overrides,
    ]);
}

/**
 * Writes one knowledge base file under the test root.
 *
 * @param  array<string, string>  $meta
 */
function kbFile(string $root, string $path, array $meta, string $body = 'نص المقال.'): void
{
    $lines = array_map(fn (string $k, string $v) => "{$k}: {$v}", array_keys($meta), $meta);
    File::ensureDirectoryExists(dirname($root.'/'.$path));
    File::put($root.'/'.$path, "---\n".implode("\n", $lines)."\n---\n\n".$body."\n");
}

/**
 * @param  list<string>  $capabilities
 */
function kbLicense(array $capabilities): void
{
    LicenseFactory::install(['capabilities' => $capabilities]);
    Facade::clearResolvedInstances();
    app()->forgetInstance(Authorizer::class);
    app()->forgetInstance(LicenseCapabilities::class);
}

/**
 * @return list<string>
 */
function kbSlugs(Assert $page, string $prop = 'categories'): array
{
    $categories = $page->toArray()['props'][$prop];

    return collect($categories)->flatMap(fn (array $c) => array_column($c['articles'], 'slug'))->values()->all();
}

describe('permissions', function () {
    it('hides an article from a member without any of its permissions: list, page, search, drawer and image', function () {
        kbArticle(['slug' => 'branch-admin', 'title_ar' => 'إدارة الفروع كانغارو', 'permissions' => ['P-03'], 'page_key' => 'org.branches.index']);
        kbArticle(['slug' => 'open-to-all', 'title_ar' => 'مقال عام كانغارو']);
        $sales = $this->org->member('sales', [$this->org->a]);

        $this->actingAs($sales)->get(route('help.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => expect(kbSlugs($page))->toBe(['open-to-all']));
        $this->actingAs($sales)->get(route('help.article', 'branch-admin'))->assertNotFound();
        $this->actingAs($sales)->get(route('help.category', 'daily_work'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('articles', 1)->where('articles.0.slug', 'open-to-all'));
        $this->actingAs($sales)->getJson(route('help.panel', ['path' => '/org/branches']))->assertOk()->assertJsonPath('article', null);
        $this->actingAs($sales)->get(route('help.context', ['path' => '/org/branches']))->assertRedirect(route('help.index'));

        $titles = collect(app(SearchService::class)->search($sales, 'كانغارو'))->firstWhere('key', 'help')['results'] ?? [];
        expect(array_column($titles, 'title'))->toBe(['مقال عام كانغارو']);
    });

    it('shows the article to whoever holds any of its permissions', function () {
        kbArticle(['slug' => 'branch-admin', 'title_ar' => 'إدارة الفروع كانغارو', 'permissions' => ['P-03', 'P-99'], 'page_key' => 'org.branches.index']);
        $manager = $this->org->member('manager', [$this->org->a]);

        $this->actingAs($manager)->get(route('help.article', 'branch-admin'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('article.locked', false));
        $this->actingAs($manager)->getJson(route('help.panel', ['path' => '/org/branches']))->assertOk()
            ->assertJsonPath('article.slug', 'branch-admin')
            ->assertJsonPath('article.url', '/help/branch-admin');

        $results = collect(app(SearchService::class)->search($manager, 'كانغارو'))->firstWhere('key', 'help')['results'] ?? [];
        expect(array_column($results, 'title'))->toBe(['إدارة الفروع كانغارو']);
    });
});

describe('audiences', function () {
    it('never shows staff articles to a customer, nor customer articles to staff', function () {
        kbArticle(['slug' => 'staff-only', 'audience' => 'staff']);
        kbArticle(['slug' => 'track-my-order', 'audience' => 'customer', 'title_ar' => 'تتبع طلبي']);
        $customer = Customer::factory()->create(['branch_id' => $this->org->a->id, 'portal_login_enabled' => true]);

        $this->actingAs($this->org->owner)->get(route('help.index'))
            ->assertInertia(fn (Assert $page) => expect(kbSlugs($page))->toBe(['staff-only']));
        $this->actingAs($this->org->owner)->get(route('help.article', 'track-my-order'))->assertNotFound();

        Auth::forgetGuards();
        actingAsCustomer($customer);
        $this->get(route('customer.help.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/help')
                ->where('categories', fn ($categories) => collect($categories)->flatMap(fn ($c) => array_column($c['articles'], 'slug'))->values()->all() === ['track-my-order']));
        $this->get(route('customer.help.article', 'track-my-order'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/help-article')->where('article.title', 'تتبع طلبي'));
        $this->get(route('customer.help.article', 'staff-only'))->assertNotFound();
    });

    it('keeps the staff help center away from a customer session', function () {
        kbArticle(['slug' => 'staff-only']);
        $customer = Customer::factory()->create(['branch_id' => $this->org->a->id, 'portal_login_enabled' => true]);

        actingAsCustomer($customer);
        $this->get(route('help.article', 'staff-only'))->assertRedirect('/login');
    });
});

describe('license capability', function () {
    it('shows an unlicensed feature article to the owner and managers only, with the higher-plan badge', function () {
        kbLicense([]);
        kbArticle(['slug' => 'store-guide', 'title_ar' => 'المتجر كانغارو', 'capability' => 'ready_goods']);
        $manager = $this->org->member('manager', [$this->org->a]);
        $inventory = $this->org->member('inventory', [$this->org->a]);

        foreach ([$this->org->owner, $manager] as $user) {
            $this->actingAs($user)->get(route('help.index'))
                ->assertInertia(fn (Assert $page) => $page->where('categories.0.articles.0.slug', 'store-guide')->where('categories.0.articles.0.locked', true));
            $this->actingAs($user)->get(route('help.article', 'store-guide'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('article.locked', true));
        }

        $this->actingAs($inventory)->get(route('help.index'))
            ->assertInertia(fn (Assert $page) => expect(kbSlugs($page))->toBe([]));
        $this->actingAs($inventory)->get(route('help.article', 'store-guide'))->assertNotFound();
        expect(app(SearchService::class)->search($inventory, 'كانغارو'))->toBe([]);
    });

    it('shows a licensed feature article without the badge to everyone allowed', function () {
        kbArticle(['slug' => 'store-guide', 'capability' => 'ready_goods', 'permissions' => ['P-12']]);
        $inventory = $this->org->member('inventory', [$this->org->a]);

        $this->actingAs($inventory)->get(route('help.article', 'store-guide'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('article.locked', false));
        $this->actingAs($this->org->owner)->get(route('help.article', 'store-guide'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('article.locked', false));
    });
});

describe('help:sync', function () {
    beforeEach(function () {
        kbFile($this->kbRoot, 'ar/staff/create-quote.md', [
            'slug' => 'create-quote', 'title' => 'كيف أنشئ عرض سعر', 'summary' => 'سطر واحد', 'category' => 'daily_work',
            'roles' => '[owner, sales]', 'permissions' => '[P-24]', 'page_key' => 'dashboard', 'order' => '10',
        ], "خطوات.\n\n![الشاشة](images/create-quote-1.png)");
        kbFile($this->kbRoot, 'en/staff/create-quote.md', ['slug' => 'create-quote', 'title' => 'How do I create a quote'], 'Steps.');
        kbFile($this->kbRoot, 'ar/customer/track-order.md', [
            'slug' => 'track-order', 'title' => 'تتبع طلبك', 'summary' => 'من مساحة العميل', 'category' => 'faq', 'capability' => 'ready_goods',
        ]);
        File::ensureDirectoryExists($this->kbRoot.'/images');
        File::put($this->kbRoot.'/images/create-quote-1.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    });

    it('creates the articles once, then changes nothing on the next run', function () {
        $this->artisan('help:sync', ['--path' => $this->kbRoot])->expectsOutputToContain('Created 2, updated 0, unchanged 0, deleted 0')->assertSuccessful();

        $article = HelpArticle::query()->where('slug', 'create-quote')->firstOrFail();
        expect($article->audience->value)->toBe('staff')
            ->and($article->roles)->toBe(['owner', 'sales'])
            ->and($article->permissions)->toBe(['P-24'])
            ->and($article->page_key)->toBe('dashboard')
            ->and($article->sort)->toBe(10)
            ->and($article->title_en)->toBe('How do I create a quote')
            ->and($article->source_path)->toBe('ar/staff/create-quote.md')
            ->and(HelpArticle::query()->where('slug', 'track-order')->firstOrFail()->audience->value)->toBe('customer');
        $before = HelpArticle::query()->orderBy('id')->get(['id', 'updated_at', 'published_at'])->toArray();

        $this->travel(5)->minutes();
        $this->artisan('help:sync', ['--path' => $this->kbRoot])->expectsOutputToContain('Created 0, updated 0, unchanged 2, deleted 0')->assertSuccessful();

        expect(HelpArticle::query()->orderBy('id')->get(['id', 'updated_at', 'published_at'])->toArray())->toBe($before);
    });

    it('updates a changed file and deletes the article of a removed file, leaving hand-made rows alone', function () {
        $this->artisan('help:sync', ['--path' => $this->kbRoot])->assertSuccessful();
        kbArticle(['slug' => 'hand-made']);

        kbFile($this->kbRoot, 'ar/staff/create-quote.md', ['slug' => 'create-quote', 'title' => 'عنوان جديد', 'summary' => 'سطر', 'category' => 'daily_work']);
        File::delete($this->kbRoot.'/ar/customer/track-order.md');

        $this->artisan('help:sync', ['--path' => $this->kbRoot])->expectsOutputToContain('Created 0, updated 1, unchanged 0, deleted 1')->assertSuccessful();

        expect(HelpArticle::query()->where('slug', 'create-quote')->value('title_ar'))->toBe('عنوان جديد')
            ->and(HelpArticle::query()->where('slug', 'track-order')->exists())->toBeFalse()
            ->and(HelpArticle::query()->where('slug', 'hand-made')->exists())->toBeTrue();
    });

    it('takes the capability of the permissions when every one needs the same', function () {
        kbFile($this->kbRoot, 'ar/staff/store-products.md', ['slug' => 'store-products', 'title' => 'منتجات الجاهز', 'summary' => 'س', 'category' => 'daily_work', 'permissions' => '[P-12]']);

        $this->artisan('help:sync', ['--path' => $this->kbRoot])->assertSuccessful();

        expect(HelpArticle::query()->where('slug', 'store-products')->value('capability'))->toBe('ready_goods');
    });

    it('rejects a file without a required key and changes nothing', function (string $missing) {
        $meta = ['slug' => 'broken', 'title' => 'ع', 'summary' => 'س', 'category' => 'faq'];
        unset($meta[$missing]);
        kbFile($this->kbRoot, 'ar/staff/broken.md', $meta);

        ['errors' => $errors] = (new ArticleLibrary($this->kbRoot))->load();
        expect($errors)->toContain("ar/staff/broken.md: missing required key «{$missing}»");

        $this->artisan('help:sync', ['--path' => $this->kbRoot])->assertFailed();
        expect(HelpArticle::query()->count())->toBe(0);
    })->with(ArticleLibrary::REQUIRED);

    it('links articles of the same audience on the reader\'s side and rejects any other target', function () {
        kbFile($this->kbRoot, 'ar/customer/pay.md', ['slug' => 'pay', 'title' => 'الدفع', 'summary' => 'س', 'category' => 'faq'], 'انظر [التتبع](article:track-order) و[داخلي](article:create-quote) و[مفقود](article:nowhere).');

        $errors = (new ArticleLibrary($this->kbRoot))->load()['errors'];
        expect($errors)->toBe([
            'ar/customer/pay.md: link «article:create-quote» does not name a customer article',
            'ar/customer/pay.md: link «article:nowhere» does not name a customer article',
        ]);

        kbFile($this->kbRoot, 'ar/customer/pay.md', ['slug' => 'pay', 'title' => 'الدفع', 'summary' => 'س', 'category' => 'faq'], 'انظر [التتبع](article:track-order).');
        $this->artisan('help:sync', ['--path' => $this->kbRoot])->assertSuccessful();
        $customer = Customer::factory()->create(['branch_id' => $this->org->a->id, 'portal_login_enabled' => true]);
        actingAsCustomer($customer);

        $this->get(route('customer.help.article', 'pay'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('article.body_html', fn (string $html) => str_contains($html, '<a href="/portal/help/track-order">التتبع</a>')));
    });

    it('rejects unknown references, keys, images and mismatched slugs', function () {
        kbFile($this->kbRoot, 'ar/staff/bad.md', [
            'slug' => 'other-name', 'title' => 'ع', 'summary' => 'س', 'category' => 'nope', 'roles' => '[ceo]',
            'permissions' => '[P-999, P-72]', 'capability' => 'store', 'page_key' => 'no.such.page', 'colour' => 'red',
        ], '![x](../../.env) ![y](images/bad-1.png)');
        kbFile($this->kbRoot, 'ar/customer/bad-customer.md', ['slug' => 'bad-customer', 'title' => 'ع', 'summary' => 'س', 'category' => 'faq', 'permissions' => '[P-01]', 'page_key' => 'dashboard']);

        $errors = implode("\n", (new ArticleLibrary($this->kbRoot))->load()['errors']);

        foreach (['must match the file name', 'category must be one of', 'unknown role «ceo»', 'unknown permission «P-999»',
            'permission «P-72» is not a staff permission', 'unknown capability «store»', 'page_key «no.such.page» is not a route name',
            'unknown key «colour»', 'image «../../.env» must be', 'customer articles cannot require permissions',
            'page_key «dashboard» belongs to the other audience'] as $expected) {
            expect($errors)->toContain($expected);
        }
    });
});

describe('the shipped knowledge base', function () {
    it('has no invalid file: every required key, permission, page key, capability and image exists', function () {
        ['articles' => $articles, 'errors' => $errors] = (new ArticleLibrary(ArticleLibrary::defaultRoot()))->load();

        expect($errors)->toBe([])->and($articles)->not->toBeEmpty();
    });

    it('only references permissions, roles, page keys and capabilities the system has (read straight from the files)', function () {
        $staffPermissions = array_map(fn (Permission $p) => $p->value, array_filter(Permission::cases(), fn (Permission $p) => in_array($p->audience(), [Audience::Staff, Audience::Self], true)));

        foreach (File::glob(ArticleLibrary::defaultRoot().'/ar/*/*.md') as $path) {
            ['meta' => $meta] = FrontMatter::parse(File::get($path));
            $name = basename($path);

            foreach (ArticleLibrary::REQUIRED as $key) {
                expect($meta[$key] ?? null)->toBeString("{$name}: {$key}")->not->toBe('');
            }
            expect(HelpCategory::tryFrom((string) $meta['category']))->not->toBeNull("{$name}: category");
            foreach ((array) ($meta['permissions'] ?? []) as $code) {
                expect($staffPermissions)->toContain($code);
            }
            foreach ((array) ($meta['roles'] ?? []) as $role) {
                expect(array_keys(PermissionCatalogue::ROLES))->toContain($role);
            }
            if (isset($meta['page_key'])) {
                expect(Route::has((string) $meta['page_key']))->toBeTrue("{$name}: page_key {$meta['page_key']}");
            }
            if (isset($meta['capability'])) {
                expect(LicensedCapabilities::KNOWN)->toContain($meta['capability']);
            }
        }
    });

    it('lists every capability the permission catalogue gates on', function () {
        foreach (Permission::cases() as $permission) {
            if ($permission->capability() !== null) {
                expect(LicensedCapabilities::KNOWN)->toContain($permission->capability());
            }
        }
    });
});

describe('images and rendering', function () {
    it('renders Markdown safely and points images at the authorized image route', function () {
        kbArticle(['slug' => 'kb-sample', 'body_ar' => "## خطوات\n\n![الشاشة](images/kb-sample-1.png)\n\n![خارجية](https://evil.test/x.png)\n\n<script>alert(1)</script>\n\n[رابط](javascript:alert(1))\n\nانظر [الفروع](article:branches)."]);

        $this->actingAs($this->org->owner)->get(route('help.article', 'kb-sample'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('article.body_html', function (string $html) {
                expect($html)->toContain('<h2>خطوات</h2>')
                    ->toContain('<img src="/help/images/kb-sample-1.png" alt="الشاشة"')
                    ->not->toContain('evil.test')
                    ->not->toContain('<script')
                    ->not->toContain('javascript:')
                    ->toContain('<a href="/help/branches">الفروع</a>');

                return true;
            }));
    });

    it('serves an image only to readers of its article and never outside the images folder', function () {
        $image = resource_path('help/images/kb-sample-1.png');
        File::put($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

        try {
            kbArticle(['slug' => 'kb-sample', 'permissions' => ['P-03']]);
            $sales = $this->org->member('sales', [$this->org->a]);

            $this->actingAs($this->org->owner)->get('/help/images/kb-sample-1.png')->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->actingAs($sales)->get('/help/images/kb-sample-1.png')->assertNotFound();
            $this->actingAs($this->org->owner)->get('/help/images/kb-sample-2.png')->assertNotFound();
            $this->actingAs($this->org->owner)->get('/help/images/..%2F..%2F.env')->assertNotFound();
            $this->actingAs($this->org->owner)->get('/help/images/kb-sample-1.png.php')->assertNotFound();
        } finally {
            File::delete($image);
        }
    });
});

describe('My guide and Start here', function () {
    it('lists the role guides of my roles, then the articles written for my roles', function () {
        kbArticle(['slug' => 'sales-guide', 'category' => 'role_guide', 'roles' => ['sales'], 'body_ar' => '**مرحباً**']);
        kbArticle(['slug' => 'manager-guide', 'category' => 'role_guide', 'roles' => ['manager']]);
        kbArticle(['slug' => 'quotes', 'category' => 'daily_work', 'roles' => ['sales', 'manager'], 'sort' => 5]);
        kbArticle(['slug' => 'untagged', 'category' => 'daily_work']);
        $sales = $this->org->member('sales', [$this->org->a]);

        $this->actingAs($sales)->get(route('help.guide'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('help/guide')
                ->where('roles', ['مبيعات'])
                ->has('guides', 1)->where('guides.0.slug', 'sales-guide')->where('guides.0.body_html', "<p><strong>مرحباً</strong></p>\n")
                ->has('articles', 1)->where('articles.0.slug', 'quotes'));

        // Role guides stay out of the help center sections.
        $this->actingAs($sales)->get(route('help.index'))
            ->assertInertia(fn (Assert $page) => expect(kbSlugs($page))->toBe(['untagged', 'quotes']));
    });

    it('shows Start here until the user closes it, per user', function () {
        kbArticle(['slug' => 'welcome', 'category' => 'getting_started']);
        kbArticle(['slug' => 'setup', 'category' => 'getting_started', 'permissions' => ['P-02']]);
        $sales = $this->org->member('sales', [$this->org->a]);

        $this->actingAs($sales)->get(route('help.index'))
            ->assertInertia(fn (Assert $page) => $page->has('startHere', 1)->where('startHere.0.slug', 'welcome'));
        $this->actingAs($this->org->owner)->get(route('help.index'))
            ->assertInertia(fn (Assert $page) => $page->has('startHere', 2));

        $this->actingAs($sales)->post(route('help.start-here.dismiss'))->assertRedirect();

        $this->actingAs($sales)->get(route('help.index'))->assertInertia(fn (Assert $page) => $page->where('startHere', null));
        $this->actingAs($this->org->owner)->get(route('help.index'))->assertInertia(fn (Assert $page) => $page->has('startHere', 2));
    });
});
