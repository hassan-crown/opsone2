<?php

namespace App\Actions\Catalog;

use App\Access\Permission;
use App\Audit\AuditAction;
use App\Catalog\CatalogWriter;
use App\Models\Catalog\CatalogProduct;
use App\Models\User;

/**
 * P-11: create or edit a made-to-order product. An edit that changes the specification writes a new version.
 */
final class SaveProduct
{
    public function __construct(private readonly CatalogWriter $writer) {}

    /**
     * @param  array<string, mixed>  $data  category, name, description, pricing_basis, reference_price_minor, option_groups
     */
    public function handle(User $actor, ?CatalogProduct $product, array $data): CatalogProduct
    {
        return $this->writer->save(
            $actor, Permission::P11, $product ?? new CatalogProduct, $data,
            AuditAction::CatalogProductCreated, AuditAction::CatalogProductUpdated,
        );
    }
}
