<?php

namespace App\Actions\Catalog;

use App\Access\Permission;
use App\Audit\AuditAction;
use App\Catalog\CatalogStatus;
use App\Catalog\CatalogWriter;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\User;

/**
 * P-12 (license capability `ready_goods`): create or edit a ready-goods product. A new product starts as a draft.
 */
final class SaveReadyProduct
{
    public function __construct(private readonly CatalogWriter $writer) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, ?CatalogReadyProduct $product, array $data): CatalogReadyProduct
    {
        $product ??= new CatalogReadyProduct(['status' => CatalogStatus::Draft]);

        return $this->writer->save(
            $actor, Permission::P12, $product, $data,
            AuditAction::CatalogReadyCreated, AuditAction::CatalogReadyUpdated,
        );
    }
}
