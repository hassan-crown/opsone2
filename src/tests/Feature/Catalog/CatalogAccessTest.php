<?php

/**
 * P-11 / P-12 / P-13 (matrix v1.1): the catalog is facility-wide, so each permission has two outcomes — a holder
 * of the role (any facility-wide grant) and everyone else. P-12 additionally needs the license capability
 * `ready_goods`. Every route enforces it server-side; a hidden tab is never the control.
 */

use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

require_once __DIR__.'/CatalogTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->sales = $this->org->member('sales', [$this->org->a]);
    $this->inventory = $this->org->member('inventory', [$this->org->a]);
    $this->finance = $this->org->member('finance', [$this->org->a]);
    $this->product = makeProduct($this->org->owner);
    $this->material = makeMaterial($this->org->owner);
    $this->ready = makeReady($this->org->owner);
});

it('P-11: owner, manager and sales manage custom products; inventory and finance do not', function () {
    foreach ([$this->org->owner, $this->org->member('manager', [$this->org->a]), $this->sales] as $user) {
        $this->actingAs($user)->get(route('catalog.products.index'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('catalog/products/index')->where('canCreate', true));
        $this->get(route('catalog.products.edit', $this->product))->assertOk();
    }

    foreach ([$this->inventory, $this->finance] as $user) {
        $this->actingAs($user)->get(route('catalog.products.index'))->assertForbidden();
        $this->get(route('catalog.products.create'))->assertForbidden();
        $this->post(route('catalog.products.store'), ['name' => ['ar' => 'x'], 'pricing_basis' => 'piece'])->assertForbidden();
        $this->put(route('catalog.products.update', $this->product), ['name' => ['ar' => 'x'], 'pricing_basis' => 'piece'])->assertForbidden();
        $this->post(route('catalog.products.disable', $this->product))->assertForbidden();
        $this->post(route('catalog.products.enable', $this->product))->assertForbidden();
    }
    expect($this->product->refresh()->isDisabled())->toBeFalse();
});

it('P-13: owner, manager and inventory manage materials; sales and finance do not', function () {
    foreach ([$this->org->owner, $this->inventory] as $user) {
        $this->actingAs($user)->get(route('catalog.materials.index'))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('catalog/materials')->where('canCreate', true));
    }

    foreach ([$this->sales, $this->finance] as $user) {
        $this->actingAs($user)->get(route('catalog.materials.index'))->assertForbidden();
        $this->post(route('catalog.materials.store'), ['name' => ['ar' => 'x'], 'unit' => 'm', 'availability' => 'available'])->assertForbidden();
        $this->put(route('catalog.materials.update', $this->material), ['name' => ['ar' => 'x'], 'unit' => 'm', 'availability' => 'available'])->assertForbidden();
        $this->post(route('catalog.materials.disable', $this->material))->assertForbidden();
    }
    expect(CatalogMaterial::query()->count())->toBe(1)->and($this->material->refresh()->isDisabled())->toBeFalse();
});

it('P-12: owner, manager and inventory manage ready goods; sales and finance do not', function () {
    foreach ([$this->org->owner, $this->inventory] as $user) {
        $this->actingAs($user)->get(route('catalog.ready.index'))->assertOk();
    }

    foreach ([$this->sales, $this->finance] as $user) {
        $this->actingAs($user)->get(route('catalog.ready.index'))->assertForbidden();
        $this->get(route('catalog.ready.edit', $this->ready))->assertForbidden();
        $this->post(route('catalog.ready.store'), ['name' => ['ar' => 'x']])->assertForbidden();
        $this->post(route('catalog.ready.status', $this->ready), ['status' => 'published'])->assertForbidden();
        $this->post(route('catalog.ready.variants.store', $this->ready), ['code' => 'A1', 'price' => '1', 'is_default' => true])->assertForbidden();
    }
});

it('shows only the tabs a viewer may open and sends a bare /catalog to the first of them', function () {
    $this->actingAs($this->inventory)->get(route('catalog.home'))->assertRedirect(route('catalog.ready.index'));
    $this->actingAs($this->sales)->get(route('catalog.home'))->assertRedirect(route('catalog.products.index'));
    $this->actingAs($this->org->owner)->get(route('catalog.products.index'))
        ->assertInertia(fn (Assert $p) => $p->where('tabs', ['products' => true, 'ready' => true, 'materials' => true]));
    $this->actingAs($this->sales)->get(route('catalog.products.index'))
        ->assertInertia(fn (Assert $p) => $p->where('tabs', ['products' => true, 'ready' => false, 'materials' => false]));
    $this->actingAs($this->finance)->get(route('catalog.home'))->assertForbidden();
});

it('requires a signed-in user', function () {
    auth()->logout();
    $this->get(route('catalog.products.index'))->assertRedirect(route('login'));
    $this->post(route('catalog.materials.store'), [])->assertRedirect(route('login'));
});

it('ready goods need the license capability even for the owner (the Gate checks the license first)', function () {
    LicenseFactory::install(['capabilities' => ['branch_comparisons']]);

    $this->actingAs($this->org->owner)->get(route('catalog.ready.index'))->assertForbidden();
    $this->get(route('catalog.ready.create'))->assertForbidden();
    $this->get(route('catalog.ready.edit', $this->ready))->assertForbidden();
    $this->post(route('catalog.ready.store'), ['name' => ['ar' => 'جديد']])->assertForbidden();
    $this->post(route('catalog.ready.variants.store', $this->ready), ['code' => 'A1', 'price' => '10', 'is_default' => true])->assertForbidden();
    expect($this->ready->variants()->count())->toBe(0);

    // The rest of the catalog is unaffected and the tab is gone.
    $this->get(route('catalog.products.index'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('tabs.ready', false)->where('tabs.products', true)->where('tabs.materials', true));
    $this->get(route('catalog.home'))->assertRedirect(route('catalog.products.index'));
});

it('exposes no route that deletes catalog rows', function () {
    $deleting = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'catalog') && in_array('DELETE', $route->methods(), true));

    expect($deleting)->toBeEmpty();
    expect(CatalogProduct::query()->count())->toBe(1);
});
