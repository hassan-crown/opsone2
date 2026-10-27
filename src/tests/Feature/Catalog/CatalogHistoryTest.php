<?php

/**
 * COM-04..06: disabling is soft (the row and its past use stay), and every specification change appends an
 * immutable version that a later order line can freeze.
 */

use App\Actions\Catalog\SaveProduct;
use App\Actions\Catalog\SaveVariant;
use App\Actions\Catalog\SetCatalogStatus;
use App\Catalog\CatalogStatus;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Catalog\CatalogVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Org;

require_once __DIR__.'/CatalogTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->owner = $this->org->owner;
});

it('disables a product without deleting it: it stays for history but cannot be chosen again', function () {
    $product = makeProduct($this->owner);
    $material = makeMaterial($this->owner);
    $withMaterial = makeProduct($this->owner, ['name' => ['ar' => 'خزانة', 'en' => null], 'option_groups' => [
        ['key' => 'surface', 'label' => ['ar' => 'السطح', 'en' => null], 'type' => 'single', 'required' => true, 'unit' => null, 'values' => [], 'material_ids' => [$material->id]],
    ]]);

    app(SetCatalogStatus::class)->handle($this->owner, $product, CatalogStatus::Disabled);
    app(SetCatalogStatus::class)->handle($this->owner, $material, CatalogStatus::Disabled);

    // Past usage is intact: the rows, their versions and the reference to the disabled material.
    expect(CatalogProduct::query()->count())->toBe(2)
        ->and(CatalogMaterial::query()->count())->toBe(1)
        ->and($product->refresh()->isDisabled())->toBeTrue()
        ->and($product->disabled_at)->not->toBeNull()
        ->and($product->versions()->count())->toBe(1)
        ->and($withMaterial->refresh()->option_groups[0]['material_ids'])->toBe([$material->id]);

    // New selection excludes them.
    expect(CatalogProduct::query()->selectable()->pluck('id')->all())->toBe([$withMaterial->id])
        ->and(CatalogMaterial::query()->selectable()->count())->toBe(0);

    // Enabling restores it.
    app(SetCatalogStatus::class)->handle($this->owner, $product, CatalogStatus::Active);
    expect($product->refresh()->isSelectable())->toBeTrue()->and($product->disabled_at)->toBeNull();
});

it('refuses to add a disabled material to an option group but lets an existing one stay', function () {
    $material = makeMaterial($this->owner);
    $product = makeProduct($this->owner, ['option_groups' => [
        ['key' => 'surface', 'label' => ['ar' => 'السطح', 'en' => null], 'type' => 'single', 'required' => false, 'unit' => null, 'values' => [], 'material_ids' => [$material->id]],
    ]]);
    app(SetCatalogStatus::class)->handle($this->owner, $material, CatalogStatus::Disabled);

    $payload = fn (array $ids) => [
        'name' => ['ar' => 'مطبخ الركن'], 'pricing_basis' => 'linear_meter', 'reference_price' => '1250.50',
        'option_groups' => [['key' => 'surface', 'label' => ['ar' => 'السطح'], 'type' => 'single', 'material_ids' => $ids]],
    ];

    $other = makeMaterial($this->owner, ['name' => ['ar' => 'جرانيت', 'en' => null]]);
    app(SetCatalogStatus::class)->handle($this->owner, $other, CatalogStatus::Disabled);

    $this->actingAs($this->owner)->put(route('catalog.products.update', $product), $payload([$material->id]))->assertSessionDoesntHaveErrors();
    $this->put(route('catalog.products.update', $product), $payload([$material->id, $other->id]))->assertSessionHasErrors('option_groups');
});

it('writes version 1 on create and a NEW version on every specification change, never rewriting an old one', function () {
    $product = makeProduct($this->owner);
    $v1 = $product->versions()->firstOrFail();
    $frozen = $v1->payload;

    expect($product->current_version)->toBe(1)
        ->and($frozen['name']['ar'])->toBe('مطبخ الركن')
        ->and($frozen['reference_price_minor'])->toBe(125050);

    app(SaveProduct::class)->handle($this->owner, $product, [...$product->only(['category', 'pricing_basis']), 'name' => ['ar' => 'مطبخ جديد', 'en' => null], 'description' => null, 'reference_price_minor' => 99000, 'option_groups' => []]);

    $product->refresh();
    expect($product->current_version)->toBe(2)
        ->and($product->versions()->pluck('version')->all())->toBe([2, 1])
        ->and(CatalogVersion::query()->findOrFail($v1->id)->payload)->toBe($frozen)
        ->and($product->versions()->first()->payload['reference_price_minor'])->toBe(99000);
});

it('writes no version and no audit row when nothing changed', function () {
    $product = makeProduct($this->owner);

    app(SaveProduct::class)->handle($this->owner, $product, [
        'category' => 'مطابخ', 'name' => ['ar' => 'مطبخ الركن', 'en' => 'Corner kitchen'], 'description' => null,
        'pricing_basis' => 'linear_meter', 'reference_price_minor' => 125050, 'option_groups' => [],
    ]);

    expect($product->refresh()->current_version)->toBe(1)->and($product->versions()->count())->toBe(1);
});

it('does not version a status change: disabling keeps the specification version', function () {
    $product = makeProduct($this->owner);
    app(SetCatalogStatus::class)->handle($this->owner, $product, CatalogStatus::Disabled);

    expect($product->refresh()->current_version)->toBe(1)->and($product->versions()->count())->toBe(1);
});

it('keeps a version immutable: it cannot be updated or deleted', function () {
    $version = makeProduct($this->owner)->versions()->firstOrFail();

    expect(fn () => $version->update(['payload' => ['name' => 'tampered']]))->toThrow(LogicException::class)
        ->and(fn () => $version->delete())->toThrow(LogicException::class);

    expect(CatalogVersion::query()->findOrFail($version->id)->payload['name']['ar'])->toBe('مطبخ الركن');
});

it('versions a variant price change so an order line can keep the old price', function () {
    $ready = makeReady($this->owner);
    $variant = makeVariant($this->owner, $ready);
    $first = $variant->versions()->firstOrFail();

    app(SaveVariant::class)->handle($this->owner, $ready, $variant, ['code' => 'HND-60-BLK', 'name' => ['ar' => 'أسود مطفي', 'en' => 'Matte black'], 'price_minor' => 5250, 'is_default' => false]);

    $variant->refresh();
    expect($variant->price_minor)->toBe(5250)
        ->and($variant->current_version)->toBe(2)
        ->and(CatalogVersion::query()->findOrFail($first->id)->payload['price_minor'])->toBe(4500)
        ->and($first->payload['product_name']['ar'])->toBe('مقبض ألمنيوم');
});

it('keeps a disabled variant and its product for past orders but not for new ones', function () {
    $ready = makeReady($this->owner);
    $variant = makeVariant($this->owner, $ready);
    app(SetCatalogStatus::class)->handle($this->owner, $variant, CatalogStatus::Published);
    app(SetCatalogStatus::class)->handle($this->owner, $ready, CatalogStatus::Published);
    expect($variant->refresh()->isSelectable())->toBeTrue();

    app(SetCatalogStatus::class)->handle($this->owner, $ready, CatalogStatus::Disabled);
    expect($variant->refresh()->isSelectable())->toBeFalse()
        ->and(CatalogReadyVariant::query()->count())->toBe(1);

    app(SetCatalogStatus::class)->handle($this->owner, $ready, CatalogStatus::Published);
    app(SetCatalogStatus::class)->handle($this->owner, $variant, CatalogStatus::Disabled);
    expect($variant->refresh()->isSelectable())->toBeFalse();
});
