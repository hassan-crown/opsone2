<?php

use App\Actions\Catalog\SaveMaterial;
use App\Actions\Catalog\SaveProduct;
use App\Actions\Catalog\SaveReadyProduct;
use App\Actions\Catalog\SaveVariant;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\User;

/**
 * @param  array<string, mixed>  $overrides
 */
function makeProduct(User $actor, array $overrides = []): CatalogProduct
{
    return app(SaveProduct::class)->handle($actor, null, [
        'category' => 'مطابخ',
        'name' => ['ar' => 'مطبخ الركن', 'en' => 'Corner kitchen'],
        'description' => null,
        'pricing_basis' => 'linear_meter',
        'reference_price_minor' => 125050,
        'option_groups' => [],
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeMaterial(User $actor, array $overrides = []): CatalogMaterial
{
    return app(SaveMaterial::class)->handle($actor, null, [
        'material_group' => 'الأسطح',
        'name' => ['ar' => 'رخام كرارا', 'en' => 'Carrara marble'],
        'description' => null,
        'unit' => 'م²',
        'availability' => 'available',
        'supplier_name' => null,
        'track_quantity' => false,
        ...$overrides,
    ]);
}

function makeReady(User $actor, string $name = 'مقبض ألمنيوم'): CatalogReadyProduct
{
    return app(SaveReadyProduct::class)->handle($actor, null, ['name' => ['ar' => $name, 'en' => null], 'description' => null]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeVariant(User $actor, CatalogReadyProduct $product, array $overrides = []): CatalogReadyVariant
{
    return app(SaveVariant::class)->handle($actor, $product, null, [
        'code' => 'HND-60-BLK',
        'name' => ['ar' => 'أسود مطفي', 'en' => 'Matte black'],
        'price_minor' => 4500,
        'is_default' => false,
        ...$overrides,
    ]);
}
