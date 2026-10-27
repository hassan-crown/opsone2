<?php

use App\Models\Branch;
use App\Models\HelpArticle;
use App\Models\User;
use App\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->org->a->update(['name_en' => 'Zebra Alpha', 'name_ar' => 'فرع الزيبرا ألفا']);
    $this->org->c->update(['name_en' => 'Zebra Charlie', 'name_ar' => 'فرع الزيبرا تشارلي']);
});

function titles(array $groups, string $key): array
{
    foreach ($groups as $group) {
        if ($group['key'] === $key) {
            return array_map(fn ($r) => $r->title, $group['results']);
        }
    }

    return [];
}

it('never returns branches outside the manager scope', function () {
    $manager = $this->org->member('manager', [$this->org->a], scope: 'branches');
    app()->setLocale('en');

    $groups = app(SearchService::class)->search($manager, 'Zebra');

    expect(titles($groups, 'branches'))->toBe(['Zebra Alpha']);
});

it('returns every branch to the owner', function () {
    app()->setLocale('en');

    $groups = app(SearchService::class)->search($this->org->owner, 'Zebra');

    expect(titles($groups, 'branches'))->toEqualCanonicalizing(['Zebra Alpha', 'Zebra Charlie']);
});

it('returns no members or branches to a user without those grants', function () {
    $sales = $this->org->member('sales', [$this->org->a]);
    $this->org->member('sales', [$this->org->c])->update(['name' => 'Zebra Colleague']);

    $groups = app(SearchService::class)->search($sales, 'Zebra');

    expect(titles($groups, 'branches'))->toBe([])
        ->and(titles($groups, 'members'))->toBe([]);
});

it('hides members of out-of-scope branches from a scoped manager', function () {
    $manager = $this->org->member('manager', [$this->org->a], scope: 'branches');
    $inside = $this->org->member('sales', [$this->org->a]);
    $outside = $this->org->member('sales', [$this->org->c]);
    $inside->update(['name' => 'Quokka Inside']);
    $outside->update(['name' => 'Quokka Outside']);

    $groups = app(SearchService::class)->search($manager, 'Quokka');

    expect(titles($groups, 'members'))->toBe(['Quokka Inside']);
});

it('serves the suggest endpoint without totals and without hidden titles', function () {
    $manager = $this->org->member('manager', [$this->org->a], scope: 'branches');
    app()->setLocale('en');

    $response = $this->actingAs($manager)->getJson(route('search.suggest', ['q' => 'Zebra']))->assertOk();

    expect($response->json())->toHaveKey('groups')->not->toHaveKey('total')
        ->and(json_encode($response->json()))->not->toContain('Charlie');
});

it('requires a signed-in user', function () {
    $this->getJson(route('search.suggest', ['q' => 'abc']))->assertUnauthorized();
});

it('ignores terms shorter than two letters and treats operators as plain text', function () {
    $service = app(SearchService::class);

    expect($service->search($this->org->owner, 'a'))->toBe([])
        ->and($service->search($this->org->owner, '+- "*() <>~@'))->toBe([]);
});

it('finds only published help articles', function () {
    HelpArticle::query()->create([
        'slug' => 'live', 'category' => 'faq', 'title_ar' => 'مقال منشور كانغارو', 'body_ar' => 'x', 'published_at' => now()->subDay(),
    ]);
    HelpArticle::query()->create([
        'slug' => 'draft', 'category' => 'faq', 'title_ar' => 'مقال مسودة كانغارو', 'body_ar' => 'x', 'published_at' => null,
    ]);

    $groups = app(SearchService::class)->search($this->org->owner, 'كانغارو');

    expect(titles($groups, 'help'))->toBe(['مقال منشور كانغارو']);
});

it('keeps branches in the query when matching the owner by code', function () {
    $code = Branch::query()->whereKey($this->org->a->id)->value('code');

    $groups = app(SearchService::class)->search($this->org->owner, $code);

    expect(titles($groups, 'branches'))->not->toBeEmpty();
});

it('rate-limits the search page and the suggestions in their own bucket', function () {
    $this->actingAs($this->org->owner);

    foreach (range(1, 30) as $i) {
        $this->get(route('search.index', ['q' => 'zebra']))->assertOk();
        $this->getJson(route('search.suggest', ['q' => 'zebra']))->assertOk();
    }
    $this->get(route('search.index', ['q' => 'zebra']))->assertTooManyRequests();
    $this->getJson(route('search.suggest', ['q' => 'zebra']))->assertTooManyRequests();

    // Another throttled feature keeps its own budget.
    $this->post(route('help.support.store'), ['category' => 'question', 'subject' => 'Still works', 'message' => 'Another bucket entirely.'])
        ->assertRedirect();
});

it('gives a support identity only what its live grant opens', function () {
    $support = User::factory()->create();
    $support->forceFill(['is_support' => true])->save();

    expect(app(SearchService::class)->search($support, 'zebra'))->toBe([]);
});
