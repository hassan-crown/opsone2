<?php

use App\Access\Authorizer;
use App\Access\Contracts\LicenseCapabilities;
use App\Access\LicensedCapabilities;
use App\Access\PermissionCatalogue;
use App\Help\HelpReader;
use App\Help\KnowledgeBase\ArticleFile;
use App\Help\KnowledgeBase\ArticleLibrary;
use App\Help\KnowledgeBase\HelpSync;
use App\Help\Pdf\RoleBookBuilder;
use App\Help\Pdf\RoleBookHtml;
use App\Help\Pdf\RoleBookPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

/*
| help:pdf (ADR-0049): one PDF per role from the knowledge base files. The selection mirrors HelpReader, the
| intermediate HTML carries the contents and the internal links, and no article of the other audience is printed.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    LicenseFactory::install(['capabilities' => LicensedCapabilities::KNOWN]);
    Facade::clearResolvedInstances();
    app()->forgetInstance(Authorizer::class);
    app()->forgetInstance(LicenseCapabilities::class);

    $this->root = storage_path('framework/testing/help-pdf-'.getmypid().'-'.bin2hex(random_bytes(4)));
    $this->out = $this->root.'-out';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    File::ensureDirectoryExists($this->root.'/images');
    File::put($this->root.'/images/s-links-1.png', $png);

    $files = [
        'ar/staff/s-open.md' => [['category' => 'daily_work', 'order' => '5', 'title' => 'مقال مفتوح'], 'نص مفتوح.'],
        'ar/staff/s-branches.md' => [['category' => 'settings', 'order' => '1', 'permissions' => '[P-03]', 'title' => 'إدارة الفروع'], 'نص الفروع.'],
        'ar/staff/s-store.md' => [['category' => 'daily_work', 'order' => '9', 'permissions' => '[P-12]', 'capability' => 'ready_goods', 'title' => 'المتجر'], 'نص المتجر.'],
        'ar/staff/s-guide-manager.md' => [['category' => 'role_guide', 'roles' => '[manager]', 'order' => '1', 'title' => 'دليل المدير'], 'دليل المدير.'],
        'ar/staff/s-guide-sales.md' => [['category' => 'role_guide', 'roles' => '[sales]', 'order' => '1', 'title' => 'دليل المبيعات'], 'دليل المبيعات.'],
        'ar/staff/s-links.md' => [['category' => 'daily_work', 'order' => '2', 'title' => 'روابط وصور'],
            "اقرأ [المفتوح](article:s-open) و[الفروع](article:s-branches).\n\n![لقطة](images/s-links-1.png)\n\n## عنوان فرعي\n\n| أ | ب |\n|---|---|\n| 1 | 2 |"],
        'ar/customer/c-guide.md' => [['category' => 'role_guide', 'order' => '1', 'title' => 'دليل العميل'], 'دليل العميل.'],
        'ar/customer/c-track.md' => [['category' => 'daily_work', 'order' => '2', 'title' => 'تتبع طلبي'], 'راجع [الدليل](article:c-guide).'],
    ];
    foreach ($files as $path => [$meta, $body]) {
        $lines = ['slug: '.basename($path, '.md'), 'summary: ملخص '.basename($path, '.md')];
        foreach ($meta as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        File::ensureDirectoryExists(dirname($this->root.'/'.$path));
        File::put($this->root.'/'.$path, "---\n".implode("\n", $lines)."\n---\n\n".$body."\n");
    }
});

afterEach(function () {
    File::deleteDirectory($this->root);
    File::deleteDirectory($this->out);
});

/**
 * @return list<ArticleFile>
 */
function pdfLibrary(string $root): array
{
    $loaded = (new ArticleLibrary($root))->load();
    expect($loaded['errors'])->toBe([]);

    return $loaded['articles'];
}

/**
 * @return list<string>
 */
function pdfSlugs(string $role, string $root): array
{
    $slugs = array_map(fn (ArticleFile $a) => $a->slug, (new RoleBookBuilder)->build($role, pdfLibrary($root))->articles());
    sort($slugs);

    return $slugs;
}

it('selects the same articles HelpReader shows, for every role and for customers', function () {
    (new HelpSync(new ArticleLibrary($this->root)))->run();

    foreach (array_keys(PermissionCatalogue::ROLES) as $role) {
        $user = $role === 'owner' ? $this->org->owner : $this->org->member($role, [$this->org->a]);
        $reader = HelpReader::forStaff($user);
        $expected = $reader->query()->pluck('slug')->all();
        // The reader's «My guide» holds only its own roles' guides; the book keeps just those too.
        $expected = array_values(array_filter($expected, fn (string $slug) => ! str_starts_with($slug, 's-guide-') || $slug === 's-guide-'.$role));
        sort($expected);

        expect(pdfSlugs($role, $this->root))->toBe($expected, "role {$role}");
    }

    $customer = HelpReader::forCustomer()->query()->pluck('slug')->all();
    sort($customer);
    expect(pdfSlugs('customer', $this->root))->toBe($customer);
});

it('leaves out what a role does not hold, and never mixes audiences', function () {
    $sales = pdfSlugs('sales', $this->root);
    expect($sales)->not->toContain('s-branches')
        ->and($sales)->toContain('s-open', 's-guide-sales')->not->toContain('s-guide-manager')
        ->and($sales)->not->toContain('c-guide', 'c-track');

    expect(pdfSlugs('customer', $this->root))->toBe(['c-guide', 'c-track']);
    expect(pdfSlugs('manager', $this->root))->toContain('s-branches');
});

it('prints the role guide first, then the sections by category and order', function () {
    $book = (new RoleBookBuilder)->build('manager', pdfLibrary($this->root));

    expect(array_map(fn ($a) => $a->slug, $book->guide))->toBe(['s-guide-manager'])
        ->and(array_keys($book->sections))->toBe(['daily_work', 'settings'])
        ->and(array_map(fn ($a) => $a->slug, $book->sections['daily_work']))->toBe(['s-links', 's-open', 's-store'])
        ->and(array_map(fn ($a) => $a->slug, $book->articles()))->toBe(['s-guide-manager', 's-links', 's-open', 's-store', 's-branches']);
});

it('builds intermediate HTML with the contents, internal links and embedded images', function () {
    $book = (new RoleBookBuilder)->build('manager', pdfLibrary($this->root));
    ['html' => $html, 'images' => $images] = (new RoleBookHtml)->render($book, $this->root);

    expect($html)->toContain('الفهرس', 'dir="rtl"');
    foreach ($book->articles() as $article) {
        expect($html)->toContain('href="#a-'.$article->slug.'"')->toContain('name="a-'.$article->slug.'"');
    }
    // article:slug → #a-slug inside the article body
    expect($html)->toContain('<a href="#a-s-open">المفتوح</a>')
        ->and($html)->not->toContain('article:')
        ->and($html)->toContain('var:img_s_links_1_png')
        ->and(array_keys($images))->toBe(['img_s_links_1_png'])
        ->and($html)->toContain('<h3>عنوان فرعي</h3>')->toContain('<table>');
});

it('keeps only the text of a link to an article that is not in the book', function () {
    $book = (new RoleBookBuilder)->build('sales', pdfLibrary($this->root));
    ['html' => $html] = (new RoleBookHtml)->render($book, $this->root);

    expect($html)->toContain('<a href="#a-s-open">المفتوح</a>')
        ->and($html)->not->toContain('s-branches')
        ->and($html)->toContain('والفروع');
});

it('does not print an article of the other audience', function () {
    ['html' => $customer] = (new RoleBookHtml)->render((new RoleBookBuilder)->build('customer', pdfLibrary($this->root)), $this->root);
    ['html' => $staff] = (new RoleBookHtml)->render((new RoleBookBuilder)->build('manager', pdfLibrary($this->root)), $this->root);

    expect($customer)->toContain('تتبع طلبي')->not->toContain('مقال مفتوح')->not->toContain('دليل المدير')
        ->and($staff)->not->toContain('تتبع طلبي')->not->toContain('دليل العميل');
});

it('prints a PDF with page numbers resolved and outlines', function () {
    $book = (new RoleBookBuilder)->build('manager', pdfLibrary($this->root));
    $pdf = (new RoleBookPdf)->render($book, $this->root);

    expect(substr($pdf, 0, 5))->toBe('%PDF-')->and(strlen($pdf))->toBeGreaterThan(20_000);
});

describe('help:pdf', function () {
    it('writes one file per role under the output folder', function () {
        $this->artisan('help:pdf', ['role' => 'customer', '--path' => $this->root, '--output' => $this->out])->assertSuccessful();

        expect(is_file($this->out.'/customer.pdf'))->toBeTrue()
            ->and(substr((string) file_get_contents($this->out.'/customer.pdf'), 0, 5))->toBe('%PDF-');
    });

    it('writes every role with --all, skipping roles that have no article', function () {
        $this->artisan('help:pdf', ['--all' => true, '--path' => $this->root, '--output' => $this->out])->assertSuccessful();

        expect(is_file($this->out.'/owner.pdf'))->toBeTrue()->and(is_file($this->out.'/customer.pdf'))->toBeTrue();
    });

    it('refuses an unknown role, no role at all, and an invalid library', function () {
        $this->artisan('help:pdf', ['role' => 'nobody', '--path' => $this->root, '--output' => $this->out])->assertFailed();
        $this->artisan('help:pdf', ['--path' => $this->root, '--output' => $this->out])->assertFailed();

        File::put($this->root.'/ar/staff/broken.md', "---\nslug: broken\n---\n\nbody\n");
        expect(Artisan::call('help:pdf', ['role' => 'owner', '--path' => $this->root, '--output' => $this->out]))->toBe(1)
            ->and(glob($this->out.'/*.pdf') ?: [])->toBe([]);
    });
});
