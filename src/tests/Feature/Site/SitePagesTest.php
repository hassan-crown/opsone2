<?php

/**
 * WEB-03 / WEB-04 / M-17: draft → publish with immutable versions, per-language publishing, policies and the
 * guarantee that an unpublished draft never reaches the public site.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Site\SitePageWriter;
use App\Models\Site\SitePage;
use App\Models\Site\SitePageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Org;

require_once __DIR__.'/SiteTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->owner = $this->org->owner;
    $this->writer = app(SitePageWriter::class);
});

it('a new page is a draft nobody can see publicly', function () {
    $page = makePage($this->owner, 'draft-only', publish: false);

    expect($page->state())->toBe('draft');
    $this->get('/ar/draft-only')->assertNotFound();
    $this->get('/en/draft-only')->assertNotFound();
    $this->get('/sitemap.xml')->assertDontSee('draft-only');
});

it('publishes both languages and serves them in their own language', function () {
    makePage($this->owner);

    $this->get('/ar/about')->assertOk()->assertSee('نص عربي')->assertDontSee('English text');
    $this->get('/en/about')->assertOk()->assertSee('English text')->assertDontSee('نص عربي');
});

it('keeps the published copy live while a draft is being edited and never leaks the draft', function () {
    $page = makePage($this->owner);
    $this->writer->saveDraft($this->owner, $page, ['en' => pageContent('About', 'SECRET DRAFT TEXT')]);

    expect($page->refresh()->state())->toBe('published_with_draft');
    $this->get('/en/about')->assertOk()->assertSee('English text')->assertDontSee('SECRET DRAFT TEXT');

    $this->writer->publish($this->owner, $page, ['en']);
    $this->get('/en/about')->assertSee('SECRET DRAFT TEXT')->assertDontSee('English text');
    $this->get('/ar/about')->assertSee('نص عربي');
});

it('published versions are immutable rows; a change is a new version', function () {
    $page = makePage($this->owner);
    $published = SitePageVersion::query()->where('page_id', $page->id)->where('locale', 'en')->firstOrFail();

    expect(fn () => $published->forceFill(['title' => 'Hacked'])->save())->toThrow(LogicException::class)
        ->and(fn () => $published->delete())->toThrow(LogicException::class);

    $this->writer->saveDraft($this->owner, $page, ['en' => pageContent('About v2')]);
    $this->writer->publish($this->owner, $page->refresh(), ['en']);

    expect(SitePageVersion::query()->where('page_id', $page->id)->where('locale', 'en')->count())->toBe(2)
        ->and($published->refresh()->title)->toBe('About');
});

it('refuses to publish an incomplete language but publishes the complete one on request', function () {
    $page = $this->writer->create($this->owner, 'partial', ['ar' => 'جزئي', 'en' => '']);
    $this->writer->saveDraft($this->owner, $page, [
        'ar' => pageContent('جزئي'),
        'en' => ['title' => '', 'sections' => [['id' => 'x', 'type' => 'text', 'visible' => true, 'data' => ['heading' => '', 'body' => '']]]],
    ]);

    expect($this->writer->readiness($page)['en']['complete'])->toBeFalse()
        ->and(fn () => $this->writer->publish($this->owner, $page, ['ar', 'en']))->toThrow(ValidationException::class);
    expect($page->refresh()->state())->toBe('draft');

    expect($this->writer->publish($this->owner, $page, ['ar', 'en'], onlyComplete: true))->toBe(['ar']);
    $this->get('/ar/partial')->assertOk();
    $this->get('/en/partial')->assertRedirect('/ar/partial'); // the unpublished language is never shown, nor an empty page
});

it('keeps the last published copy of a language whose newer draft is incomplete', function () {
    $page = makePage($this->owner);
    $this->writer->saveDraft($this->owner, $page, ['ar' => ['title' => 'عنوان جديد', 'sections' => []]]);

    expect(fn () => $this->writer->publish($this->owner, $page, ['ar']))->toThrow(ValidationException::class);
    $this->get('/ar/about')->assertOk()->assertSee('نص عربي');
});

it('discards unpublished changes only', function () {
    $page = makePage($this->owner);
    $this->writer->saveDraft($this->owner, $page, ['en' => pageContent('About', 'Temp')]);
    $this->writer->discardDraft($this->owner, $page->refresh());

    expect($page->refresh()->state())->toBe('published');
    $this->get('/en/about')->assertSee('English text');
});

it('unpublish returns a content page to draft and hides it', function () {
    $page = makePage($this->owner);
    $this->writer->unpublish($this->owner, $page);

    expect($page->refresh()->state())->toBe('draft');
    $this->get('/en/about')->assertNotFound();
    expect($this->writer->readiness($page)['en']['has_draft'])->toBeTrue(); // content kept for editing
});

it('archives without deleting, redirects the public link home and restores as a draft', function () {
    $page = makePage($this->owner);
    $this->writer->archive($this->owner, $page);

    $this->get('/en/about')->assertRedirect('/en');
    expect(SitePage::query()->count())->toBeGreaterThan(0)->and(SitePageVersion::query()->where('page_id', $page->id)->count())->toBe(2);
    $this->get('/sitemap.xml')->assertDontSee('/about');

    $this->writer->restore($this->owner, $page->refresh());
    expect($page->refresh()->state())->toBe('draft');
    $this->get('/en/about')->assertNotFound(); // restore never republishes by itself

    $this->writer->publish($this->owner, $page, ['ar', 'en']);
    $this->get('/en/about')->assertOk();
});

it('protects system and edit-only pages through the writer, not only the screen (WEB-04)', function () {
    $home = SitePage::query()->where('system_key', 'home')->firstOrFail();
    $store = SitePage::query()->where('system_key', 'store')->firstOrFail();

    expect(fn () => $this->writer->archive($this->owner, $home))->toThrow(InvalidTransition::class)
        ->and(fn () => $this->writer->unpublish($this->owner, $home))->toThrow(InvalidTransition::class)
        ->and(fn () => $this->writer->saveDraft($this->owner, $store, ['ar' => pageContent('x')]))->toThrow(ValidationException::class)
        ->and(fn () => $this->writer->saveDraft($this->owner, $home, [], 'new-slug'))->toThrow(ValidationException::class);

    $this->actingAs($this->owner)->post(route('site.admin.pages.archive', $home))->assertSessionHasErrors('transition');
    $this->actingAs($this->owner)->put(route('site.admin.pages.update', $store), ['content' => ['ar' => ['title' => 'x']]])->assertSessionHasErrors();
    expect($home->refresh()->isArchived())->toBeFalse();
    $this->get('/ar/store')->assertNotFound();
});

it('the home page can be edited and republished', function () {
    $home = SitePage::query()->where('system_key', 'home')->firstOrFail();
    $this->writer->saveDraft($this->owner, $home, ['en' => ['title' => 'Welcome', 'sections' => [
        ['id' => 'h', 'type' => 'hero', 'visible' => true, 'data' => ['heading' => 'Brand new hero', 'subheading' => '', 'cta_label' => '', 'cta_link' => null, 'image' => null]],
    ]]]);
    $this->get('/en')->assertDontSee('Brand new hero');
    $this->writer->publish($this->owner, $home->refresh(), ['en']);
    $this->get('/en')->assertSee('Brand new hero');
});

it('rejects reserved and duplicate paths, and free HTML / script content', function () {
    $this->actingAs($this->owner);
    foreach (['store', 'account', 'ar', 'site-admin', 'Bad Slug', 'a/b'] as $slug) {
        $this->post(route('site.admin.pages.store'), ['slug' => $slug, 'title' => ['ar' => 'x']])->assertSessionHasErrors('slug');
    }
    makePage($this->owner, 'taken', publish: false);
    $this->post(route('site.admin.pages.store'), ['slug' => 'taken'])->assertSessionHasErrors('slug');

    $page = makePage($this->owner, 'html-test', publish: false);
    $this->put(route('site.admin.pages.update', $page), ['content' => ['en' => ['title' => 'T', 'sections' => [
        ['type' => 'text', 'visible' => true, 'data' => ['heading' => '<script>alert(1)</script>', 'body' => 'ok']],
    ]]]])->assertSessionHasErrors();
    $this->put(route('site.admin.pages.update', $page), ['content' => ['en' => ['title' => 'T', 'sections' => [
        ['type' => 'raw_html', 'visible' => true, 'data' => ['html' => '<b>x</b>']],
    ]]]])->assertSessionHasErrors();
});

it('escapes section text on the public page', function () {
    $page = $this->writer->create($this->owner, 'escape', ['en' => 'E']);
    $this->writer->saveDraft($this->owner, $page, ['en' => pageContent('E', 'a & b "quoted" 1 < 2 and 3 > 2')]);
    $this->writer->publish($this->owner, $page, ['en']);

    $this->get('/en/escape')->assertOk()->assertSee('a &amp; b &quot;quoted&quot; 1 &lt; 2 and 3 &gt; 2', false);
});

it('a published page cannot take a new path, and hero links must point to live pages', function () {
    $page = makePage($this->owner);
    expect(fn () => $this->writer->saveDraft($this->owner, $page, [], 'renamed'))->toThrow(ValidationException::class);

    $draft = $this->writer->create($this->owner, 'links', ['en' => 'L']);
    $this->writer->saveDraft($this->owner, $draft, ['en' => ['title' => 'L', 'sections' => [
        ['id' => 'h', 'type' => 'hero', 'visible' => true, 'data' => ['heading' => 'H', 'subheading' => '', 'cta_label' => 'Go', 'cta_link' => 'does-not-exist', 'image' => null]],
    ]]]);
    expect($this->writer->readiness($draft)['en']['issues'])->toContain(['section' => 0, 'code' => 'bad_link', 'field' => 'cta_link']);
});
