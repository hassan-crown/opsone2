<?php

use App\Models\HelpArticle;
use Database\Seeders\HelpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
});

function article(array $overrides = []): HelpArticle
{
    return HelpArticle::query()->create([
        'slug' => 'sample', 'category' => 'faq',
        'title_ar' => 'عنوان عربي', 'title_en' => 'English title',
        'summary_ar' => 'ملخص', 'summary_en' => null,
        'body_ar' => 'نص عربي', 'body_en' => 'English body',
        'published_at' => now()->subDay(),
        ...$overrides,
    ]);
}

it('falls back to Arabic when the English field is missing', function () {
    $a = article();

    expect($a->title('en'))->toBe('English title')
        ->and($a->summary('en'))->toBe('ملخص')
        ->and($a->title('ar'))->toBe('عنوان عربي');
});

it('falls back to English when the Arabic field is blank', function () {
    $a = article(['title_ar' => '  ']);

    expect($a->title('ar'))->toBe('English title');
});

it('renders the article in the user locale with fallback', function () {
    article();
    $this->org->owner->forceFill(['locale' => 'en'])->save();

    $this->actingAs($this->org->owner)->get(route('help.article', 'sample'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/article')
            ->where('article.title', 'English title')
            ->where('article.summary', 'ملخص')
            ->where('article.body', 'English body'));
});

it('does not serve drafts or future articles', function () {
    article(['slug' => 'draft', 'published_at' => null]);
    article(['slug' => 'future', 'published_at' => now()->addDay()]);

    $this->actingAs($this->org->owner)->get(route('help.article', 'draft'))->assertNotFound();
    $this->actingAs($this->org->owner)->get(route('help.article', 'future'))->assertNotFound();
});

it('lists published articles by category for every member', function () {
    article();
    article(['slug' => 'hidden', 'published_at' => null]);
    $sales = $this->org->member('sales', [$this->org->a]);

    $this->actingAs($sales)->get(route('help.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/index')
            ->has('categories', 1)
            ->where('categories.0.key', 'faq')
            ->has('categories.0.articles', 1));
});

it('redirects guests to sign in', function () {
    $this->get(route('help.index'))->assertRedirect(route('login'));
});

it('opens the article for the current page, else the help center', function () {
    article(['slug' => 'branches-help', 'page_key' => 'org.branches.index']);

    $this->actingAs($this->org->owner)->get(route('help.context', ['path' => '/org/branches']))
        ->assertRedirect(route('help.article', 'branches-help'));
    $this->actingAs($this->org->owner)->get(route('help.context', ['path' => '/nowhere']))
        ->assertRedirect(route('help.index'));
});

it('shows the seeded starter articles and what is new', function () {
    $this->seed(HelpSeeder::class);

    $this->actingAs($this->org->owner)->get(route('help.whats-new'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('help/whats-new')->has('entries', 1));
    expect(HelpArticle::query()->published()->count())->toBeGreaterThanOrEqual(8);
});
