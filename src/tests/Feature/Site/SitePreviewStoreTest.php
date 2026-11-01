<?php

/** THM-04: the store (catalogue, product, cart) previews with the draft theme too, and a preview never checks out. */

use App\Site\Preview\SitePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Store/StoreTestHelpers.php';

uses(RefreshDatabase::class);

it('previews the store with the draft theme too (catalogue, product, cart) but never checks out', function () {
    $s = storeFixture(5);
    $owner = $s->f->org->owner;
    $this->actingAs($owner)->put(route('site.admin.theme.save'), ['brand_color' => '#1D5FA8'])->assertSessionHasNoErrors();
    $link = $this->actingAs($owner)->postJson(route('site.admin.preview-link'), ['locale' => 'en', 'target' => 'store'])->assertOk()->json();

    auth()->logout();
    expect($this->get('/en/store')->getContent())->not->toContain('#1D5FA8');

    auth()->logout();
    $this->flushSession();
    $cookie = $this->get($link['url'])->assertRedirect()->getCookie(SitePreview::COOKIE);
    $this->withCookie(SitePreview::COOKIE, $cookie->getValue());
    foreach (['/en/store', '/en/store/p/'.$s->f->variant->ready_product_id, '/en/store/cart'] as $uri) {
        $r = $this->get($uri)->assertOk();
        expect($r->getContent())->toContain('#1D5FA8')->toContain('name="robots" content="noindex, nofollow"');
    }
    $this->post('/en/store/checkout')->assertForbidden();
    $this->post('/en/store/checkout/code', [])->assertForbidden();
});
