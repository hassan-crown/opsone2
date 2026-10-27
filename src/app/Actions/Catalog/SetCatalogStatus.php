<?php

namespace App\Actions\Catalog;

use App\Access\Permission;
use App\Audit\AuditAction;
use App\Catalog\CatalogStatus;
use App\Catalog\CatalogWriter;
use App\Models\Catalog\CatalogItem;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Disable / enable (and publish, for ready goods) — soft only: the row and every past use of it are kept, and the
 * version history is untouched. Disabled rows are excluded from `selectable()`.
 */
final class SetCatalogStatus
{
    public function __construct(private readonly CatalogWriter $writer) {}

    public function handle(User $actor, Model&CatalogItem $record, CatalogStatus $to): Model
    {
        [$permission, $action] = match (true) {
            $record instanceof CatalogProduct => [Permission::P11, $to === CatalogStatus::Disabled ? AuditAction::CatalogProductDisabled : AuditAction::CatalogProductEnabled],
            $record instanceof CatalogMaterial => [Permission::P13, $to === CatalogStatus::Disabled ? AuditAction::CatalogMaterialDisabled : AuditAction::CatalogMaterialEnabled],
            $record instanceof CatalogReadyProduct => [Permission::P12, AuditAction::CatalogReadyStatusChanged],
            $record instanceof CatalogReadyVariant => [Permission::P12, AuditAction::CatalogVariantStatusChanged],
            default => throw new \InvalidArgumentException('Not a catalog record.'),
        };

        $allowed = match (true) {
            $record instanceof CatalogReadyProduct, $record instanceof CatalogReadyVariant => [CatalogStatus::Draft, CatalogStatus::Published, CatalogStatus::Disabled],
            default => [CatalogStatus::Active, CatalogStatus::Disabled],
        };
        abort_unless(in_array($to, $allowed, true), 422);

        return $this->writer->setStatus($actor, $permission, $record, $to, $action);
    }
}
