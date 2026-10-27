<?php

namespace App\Search\Providers;

use App\Access\Access;
use App\Access\Permission;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\User;
use App\Search\FulltextMatch;
use App\Search\SearchProvider;
use App\Search\SearchResult;

/**
 * Catalog items the viewer may manage: made-to-order products (P-11), materials (P-13) and — only when the license
 * has `ready_goods` — ready products (P-12). A section the Gate denies contributes nothing, not even a count.
 */
final class CatalogSearchProvider implements SearchProvider
{
    private const CANDIDATES = 50;

    public function key(): string
    {
        return 'catalog';
    }

    public function search(User $user, string $term, int $limit): array
    {
        $results = [];

        if (Access::can($user, Permission::P11)) {
            foreach (FulltextMatch::apply(CatalogProduct::query(), ['name', 'category'], $term)->orderBy('id')->limit(self::CANDIDATES)->get() as $product) {
                if (Access::can($user, Permission::P11, $product)) {
                    $results[] = new SearchResult('catalog_product', (string) $product->name->get(), route('catalog.products.edit', $product, false), $product->category);
                }
            }
        }

        if (Access::can($user, Permission::P13)) {
            foreach (FulltextMatch::apply(CatalogMaterial::query(), ['name', 'supplier_name'], $term)->orderBy('id')->limit(self::CANDIDATES)->get() as $material) {
                if (Access::can($user, Permission::P13, $material)) {
                    $results[] = new SearchResult('catalog_material', (string) $material->name->get(), route('catalog.materials.index', ['q' => (string) $material->name->get()], false), $material->material_group);
                }
            }
        }

        if (Access::can($user, Permission::P12)) {
            foreach (FulltextMatch::apply(CatalogReadyProduct::query(), ['name'], $term)->orderBy('id')->limit(self::CANDIDATES)->get() as $ready) {
                if (Access::can($user, Permission::P12, $ready)) {
                    $results[] = new SearchResult('catalog_ready', (string) $ready->name->get(), route('catalog.ready.edit', $ready, false));
                }
            }
        }

        return array_slice($results, 0, $limit);
    }
}
