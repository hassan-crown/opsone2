<?php

/**
 * THM-01..07 / M-35: one private draft over the live theme, applied by a separate permission; the contrast guard
 * refuses or adjusts colours; logos are PNG/WebP only; restore never touches content.
 */

use App\Actions\Site\SitePageWriter;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Site\SitePage;
use App\Models\Site\SitePageVersion;
use App\Models\Site\SiteTheme;
use App\Site\Theme\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Org;

require_once __DIR__.'/SiteTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('attachments.disk'));
    $this->org = Org::make();
    $this->owner = $this->org->owner;
    $this->owner->forceFill(['locale' => 'en'])->save();
    makePage($this->owner);
});

it('starts as the default Alpha theme with no overrides on the site', function () {
    expect(SiteTheme::current()->state())->toBe('default');
    $html = $this->get('/en')->getContent();

    expect($html)->not->toContain('--brand:')->and($html)->toContain('data-surface="site"');
});

it('a saved draft changes nothing for visitors until it is applied', function () {
    $this->actingAs($this->owner)->put(route('site.admin.theme.save'), ['brand_color' => '#1D5FA8', 'font' => 'plex-sans', 'appearance' => 'dark'])->assertSessionHasNoErrors();

    expect(SiteTheme::current()->state())->toBe('draft')->and(SiteTheme::current()->published)->toBeNull();
    auth()->logout();
    $this->get('/en')->assertDontSee('--brand:', false);

    $this->actingAs($this->owner)->post(route('site.admin.theme.apply'))->assertSessionHasNoErrors();
    auth()->logout();
    $html = $this->get('/en')->getContent();

    expect(SiteTheme::current()->state())->toBe('custom')
        ->and($html)->toContain("[data-surface='site']{--brand:")
        ->and($html)->toContain("[data-theme='dark'] [data-surface='site']")
        ->and($html)->toContain("--font-sans:'IBM Plex Sans'")
        ->and($html)->toContain('<html lang="en" dir="ltr" data-theme="dark" class="dark"');
});

it('refuses a colour that is too light with a clear bilingual message and keeps the previous draft', function () {
    $this->actingAs($this->owner)->put(route('site.admin.theme.save'), ['brand_color' => '#FFFF00'])
        ->assertSessionHasErrors(['brand_color' => 'This colour is too light for text to be read on it. Choose a darker shade.']);

    expect(SiteTheme::current()->draft)->toBeNull();

    $this->owner->forceFill(['locale' => 'ar'])->save();
    $this->actingAs($this->owner->refresh())->put(route('site.admin.theme.save'), ['brand_color' => '#FFFF00'])
        ->assertSessionHasErrors(['brand_color' => 'هذا اللون فاتح جدًا ليُقرأ عليه النص. اختر درجة أغمق.']);
});

it('rejects malformed colours, fonts and modes (no free CSS)', function () {
    $this->actingAs($this->owner);
    $this->put(route('site.admin.theme.save'), ['brand_color' => 'red'])->assertSessionHasErrors('brand_color');
    $this->put(route('site.admin.theme.save'), ['brand_color' => '#0E7C72;}body{display:none'])->assertSessionHasErrors('brand_color');
    $this->put(route('site.admin.theme.save'), ['font' => 'Comic Sans'])->assertSessionHasErrors('font');
    $this->put(route('site.admin.theme.save'), ['appearance' => 'neon'])->assertSessionHasErrors('appearance');

    expect(SiteTheme::current()->draft)->toBeNull();
});

it('auto-adjusts a fixable colour so text stays readable and tells the editor', function () {
    $this->actingAs($this->owner)->put(route('site.admin.theme.save'), ['brand_color' => '#3FB8A9'])->assertSessionHasNoErrors();

    $draft = SiteTheme::current()->draft;
    expect($draft['brand_input'])->toBe('#3FB8A9')
        ->and($draft['brand'])->not->toBe('#3FB8A9')
        ->and(Color::contrast('#FFFFFF', $draft['brand']))->toBeGreaterThanOrEqual(4.5);
});

it('previews the contrast guard result as JSON without writing', function () {
    $res = $this->actingAs($this->owner)->postJson(route('site.admin.theme.preview'), ['brand_color' => '#FFFF00'])->assertOk()->json();
    expect($res['status'])->toBe('rejected')->and($res['message'])->toContain('too light');

    $ok = $this->postJson(route('site.admin.theme.preview'), ['brand_color' => '#1D5FA8'])->json();
    expect($ok['status'])->toBeIn(['ok', 'adjusted'])->and(collect($ok['report'])->every(fn ($r) => $r['pass']))->toBeTrue();
    expect(SiteTheme::current()->draft)->toBeNull();
});

it('apply fails without a draft and keeps the live theme', function () {
    $this->actingAs($this->owner)->post(route('site.admin.theme.apply'))->assertSessionHasErrors('transition');
});

it('a draft that went bad cannot be applied; the live theme and the draft stay', function () {
    $this->actingAs($this->owner)->put(route('site.admin.theme.save'), ['brand_color' => '#1D5FA8']);
    SiteTheme::current()->forceFill(['draft' => array_merge(SiteTheme::current()->draft, ['font' => 'Comic Sans'])])->save();

    $this->post(route('site.admin.theme.apply'))->assertSessionHasErrors('font');
    expect(SiteTheme::current()->published)->toBeNull()->and(SiteTheme::current()->draft)->not->toBeNull();
});

it('discard drops only the draft', function () {
    $this->actingAs($this->owner)->put(route('site.admin.theme.save'), ['brand_color' => '#1D5FA8']);
    $this->post(route('site.admin.theme.apply'));
    $this->put(route('site.admin.theme.save'), ['brand_color' => '#B3263E']);
    $this->post(route('site.admin.theme.discard'))->assertSessionHasNoErrors();

    $theme = SiteTheme::current();
    expect($theme->draft)->toBeNull()->and($theme->published['brand_input'])->toBe('#1D5FA8');
});

it('restore prepares default Alpha in the draft, applies on demand and never touches pages or content', function () {
    $this->actingAs($this->owner)->put(route('site.admin.theme.save'), ['brand_color' => '#1D5FA8']);
    $this->post(route('site.admin.theme.apply'));
    $pages = SitePage::query()->count();
    $versions = SitePageVersion::query()->count();

    $this->post(route('site.admin.theme.restore'))->assertSessionHasNoErrors();
    expect(SiteTheme::current()->published['brand_input'])->toBe('#1D5FA8'); // still live until applied

    $this->post(route('site.admin.theme.apply'))->assertSessionHasNoErrors();
    expect(SiteTheme::current()->state())->toBe('default')->and(SiteTheme::current()->published)->toBeNull()
        ->and(SitePage::query()->count())->toBe($pages)->and(SitePageVersion::query()->count())->toBe($versions);
    auth()->logout();
    $this->get('/en/about')->assertOk()->assertSee('English text');
});

it('uploads a PNG logo into the draft, and serves it publicly only after apply', function () {
    $this->actingAs($this->owner)->post(route('site.admin.theme.upload', 'logo'), ['file' => UploadedFile::fake()->image('logo.png', 400, 120)])->assertSessionHasNoErrors();

    $uuid = SiteTheme::current()->draft['logo'];
    auth()->logout();
    $this->get(route('site.media', $uuid))->assertNotFound(); // a draft image is private
    $this->get('/en')->assertDontSee($uuid, false);

    $this->actingAs($this->owner)->get(route('site.admin.media', $uuid))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->post(route('site.admin.theme.apply'));
    auth()->logout();

    $this->get(route('site.media', $uuid))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/en')->assertSee($uuid, false)->assertSee('alt="Test facility"', false);
});

it('uses the dark logo variant when one is applied', function () {
    $this->actingAs($this->owner);
    $this->post(route('site.admin.theme.upload', 'logo'), ['file' => UploadedFile::fake()->image('l.png', 300, 100)]);
    $this->post(route('site.admin.theme.upload', 'logo_dark'), ['file' => UploadedFile::fake()->image('d.png', 300, 100)]);
    $draft = SiteTheme::current()->draft;
    expect($draft['logo'])->not->toBeNull()->and($draft['logo_dark'])->not->toBeNull();
    $this->post(route('site.admin.theme.apply'));
    auth()->logout();
    $html = $this->get('/en')->getContent();
    expect($html)->toContain('dark:hidden')->and($html)->toContain($draft['logo_dark']);
});

it('refuses SVG, JPEG, scripts renamed as images and a non-square favicon (Q-50 a)', function () {
    $this->actingAs($this->owner);
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $this->post(route('site.admin.theme.upload', 'logo'), ['file' => $svg])->assertSessionHasErrors('file');

    $svgAsPng = UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    $this->post(route('site.admin.theme.upload', 'logo'), ['file' => $svgAsPng])->assertSessionHasErrors('file');

    $php = UploadedFile::fake()->createWithContent('logo.png', '<?php system($_GET["c"]);');
    $this->post(route('site.admin.theme.upload', 'logo'), ['file' => $php])->assertSessionHasErrors('file');

    $jpeg = UploadedFile::fake()->image('logo.jpg', 100, 100);
    $this->post(route('site.admin.theme.upload', 'logo'), ['file' => $jpeg])->assertSessionHasErrors('file');

    $wide = UploadedFile::fake()->image('fav.png', 200, 100);
    $this->post(route('site.admin.theme.upload', 'favicon'), ['file' => $wide])->assertSessionHasErrors('file');

    expect(SiteTheme::current()->draft)->toBeNull()->and(Attachment::query()->count())->toBe(0);
});

it('page images are PNG/WebP only, private until the page is published, and never SVG', function () {
    $page = makePage($this->owner, 'gallery', publish: false);
    $this->actingAs($this->owner);
    $this->post(route('site.admin.pages.images', $page), ['file' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])->assertSessionHasErrors('file');
    $this->post(route('site.admin.pages.images', $page), ['file' => UploadedFile::fake()->image('ok.png', 300, 200)])->assertSessionHasNoErrors();

    $uuid = $page->attachments()->firstOrFail()->uuid;
    app(SitePageWriter::class)->saveDraft($this->owner, $page, ['en' => ['title' => 'Gallery', 'sections' => [
        ['id' => 'g', 'type' => 'gallery', 'visible' => true, 'data' => ['heading' => 'Work', 'images' => [['image' => $uuid, 'caption' => 'Kitchen']]]],
    ]], 'ar' => ['title' => 'معرض', 'sections' => [
        ['id' => 'g', 'type' => 'gallery', 'visible' => true, 'data' => ['heading' => 'أعمال', 'images' => [['image' => $uuid, 'caption' => 'مطبخ']]]],
    ]]]);

    auth()->logout();
    $this->get(route('site.media', $uuid))->assertNotFound();

    $this->actingAs($this->owner);
    app(SitePageWriter::class)->publish($this->owner, $page, ['ar', 'en']);
    auth()->logout();
    $this->get(route('site.media', $uuid))->assertOk();
    $this->get('/en/gallery')->assertOk()->assertSee('alt="Kitchen"', false);

    app(SitePageWriter::class)->archive($this->owner, $page->refresh());
    $this->get(route('site.media', $uuid))->assertNotFound();
});

it('refuses a section image that belongs to another page', function () {
    $a = makePage($this->owner, 'page-a', publish: false);
    $b = makePage($this->owner, 'page-b', publish: false);
    $this->actingAs($this->owner)->post(route('site.admin.pages.images', $a), ['file' => UploadedFile::fake()->image('a.png', 100, 100)]);
    $uuid = $a->attachments()->firstOrFail()->uuid;

    $this->put(route('site.admin.pages.update', $b), ['content' => ['en' => ['title' => 'B', 'sections' => [
        ['type' => 'hero', 'visible' => true, 'data' => ['heading' => 'H', 'image' => $uuid]],
    ]]]])->assertSessionHasErrors();
});

it('does not serve unrelated private attachments through the site media routes', function () {
    $this->actingAs($this->owner);
    $other = Attachment::query()->create([
        'uuid' => (string) Str::uuid(), 'attachable_type' => Branch::class, 'attachable_id' => $this->org->a->id, 'disk' => config('attachments.disk'),
        'path' => 'x/y.png', 'original_name' => 'y.png', 'extension' => 'png', 'mime' => 'image/png', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
    ]);

    $this->get(route('site.admin.media', $other->uuid))->assertNotFound();
    auth()->logout();
    $this->get(route('site.media', $other->uuid))->assertNotFound();
});
