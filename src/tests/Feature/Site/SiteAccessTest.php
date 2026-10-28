<?php

/**
 * Authorization of the site admin screens: P-55 (edit), P-56 (publish/archive), P-100 (edit theme), P-101 (apply
 * theme, restore, domain). Every route enforces it server-side.
 */

use App\Models\Site\SitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

require_once __DIR__.'/SiteTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->manager = $this->org->member('manager', [$this->org->a]);
    $this->content = $this->org->member('content', [$this->org->a]);
    $this->sales = $this->org->member('sales', [$this->org->a]);
    $this->finance = $this->org->member('finance', [$this->org->a]);
    $this->page = makePage($this->org->owner, 'about', publish: false);
});

it('P-55 / P-56: owner, manager and content manage pages; sales and finance do not', function () {
    foreach ([$this->org->owner, $this->manager, $this->content] as $user) {
        $this->actingAs($user)->get(route('site.admin.pages.index'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('site/pages/index')->where('canCreate', true)->where('canPublish', true));
        $this->get(route('site.admin.pages.edit', $this->page))->assertOk()->assertInertia(fn (Assert $p) => $p->component('site/pages/edit')->where('can.edit', true));
    }

    foreach ([$this->sales, $this->finance] as $user) {
        $this->actingAs($user);
        $this->get(route('site.admin.pages.index'))->assertForbidden();
        $this->get(route('site.admin.pages.edit', $this->page))->assertForbidden();
        $this->post(route('site.admin.pages.store'), ['slug' => 'x'])->assertForbidden();
        $this->put(route('site.admin.pages.update', $this->page), ['content' => ['ar' => ['title' => 'x']]])->assertForbidden();
        $this->post(route('site.admin.pages.publish', $this->page), ['locales' => ['ar']])->assertForbidden();
        $this->post(route('site.admin.pages.unpublish', $this->page))->assertForbidden();
        $this->post(route('site.admin.pages.discard', $this->page))->assertForbidden();
        $this->post(route('site.admin.pages.archive', $this->page))->assertForbidden();
        $this->post(route('site.admin.pages.restore', $this->page))->assertForbidden();
        $this->post(route('site.admin.pages.images', $this->page), [])->assertForbidden();
    }
    expect($this->page->refresh()->state())->toBe('draft')->and(SitePage::query()->where('slug', 'x')->exists())->toBeFalse();
});

it('guests are sent to sign in', function () {
    $this->get(route('site.admin.pages.index'))->assertRedirect('/login');
    $this->get(route('site.admin.theme.edit'))->assertRedirect('/login');
    $this->get(route('site.admin.domain.show'))->assertRedirect('/login');
});

it('P-100 / P-101: only owner and manager touch the theme; the content role does not', function () {
    foreach ([$this->org->owner, $this->manager] as $user) {
        $this->actingAs($user)->get(route('site.admin.theme.edit'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('site/theme')->where('can.edit', true)->where('can.apply', true));
    }
    foreach ([$this->content, $this->sales, $this->finance] as $user) {
        $this->actingAs($user);
        $this->get(route('site.admin.theme.edit'))->assertForbidden();
        $this->put(route('site.admin.theme.save'), ['brand_color' => '#1D5FA8'])->assertForbidden();
        $this->post(route('site.admin.theme.apply'))->assertForbidden();
        $this->post(route('site.admin.theme.restore'))->assertForbidden();
        $this->post(route('site.admin.theme.discard'))->assertForbidden();
        $this->post(route('site.admin.theme.preview'), ['brand_color' => '#1D5FA8'])->assertForbidden();
    }
});

it('P-101: the domain screen is for owner and manager only', function () {
    $this->actingAs($this->org->owner)->get(route('site.admin.domain.show'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('site/domain'));
    foreach ([$this->content, $this->sales] as $user) {
        $this->actingAs($user);
        $this->get(route('site.admin.domain.show'))->assertForbidden();
        $this->post(route('site.admin.domain.store'), ['hostname' => 'www.mysite.sa'])->assertForbidden();
        $this->post(route('site.admin.domain.check'))->assertForbidden();
        $this->delete(route('site.admin.domain.destroy'))->assertForbidden();
    }
});

it('the site navigation entry follows the grants', function () {
    $this->actingAs($this->content)->get(route('site.admin.pages.index'))->assertInertia(fn (Assert $p) => $p->where('orgNav.site', true)->where('tabs.theme', false)->where('tabs.domain', false));
    $this->actingAs($this->sales)->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('orgNav.site', false));
});

it('an invited-but-disabled style outsider: the public site stays open to everyone (P-83)', function () {
    makePage($this->org->owner, 'open');
    $this->get('/en/open')->assertOk();
    $this->actingAs($this->sales)->get('/en/open')->assertOk();
});
