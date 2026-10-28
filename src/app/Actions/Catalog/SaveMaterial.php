<?php

namespace App\Actions\Catalog;

use App\Access\Permission;
use App\Audit\AuditAction;
use App\Catalog\CatalogWriter;
use App\Models\Catalog\CatalogMaterial;
use App\Models\User;

/**
 * P-13: create or edit a made-to-order material or model.
 */
final class SaveMaterial
{
    public function __construct(private readonly CatalogWriter $writer) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, ?CatalogMaterial $material, array $data): CatalogMaterial
    {
        return $this->writer->save(
            $actor, Permission::P13, $material ?? new CatalogMaterial, $data,
            AuditAction::CatalogMaterialCreated, AuditAction::CatalogMaterialUpdated,
        );
    }
}
