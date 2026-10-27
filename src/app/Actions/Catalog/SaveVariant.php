<?php

namespace App\Actions\Catalog;

use App\Access\Permission;
use App\Audit\AuditAction;
use App\Catalog\CatalogStatus;
use App\Catalog\CatalogWriter;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P-12: add or edit a variant (code, name, price). A price or code change writes a new version, so an order line
 * that froze the old one keeps it. Variants are never generated automatically.
 *
 * A product has either one default variant or several named ones (COM-06) — never both.
 */
final class SaveVariant
{
    public function __construct(private readonly CatalogWriter $writer) {}

    /**
     * @param  array{code: string, name?: array<string, mixed>|null, price_minor: int, is_default?: bool}  $data
     */
    public function handle(User $actor, CatalogReadyProduct $product, ?CatalogReadyVariant $variant, array $data): CatalogReadyVariant
    {
        $variant ??= new CatalogReadyVariant(['ready_product_id' => $product->id, 'status' => CatalogStatus::Draft]);
        abort_if($variant->exists && $variant->ready_product_id !== $product->id, 404);

        return DB::transaction(function () use ($actor, $product, $variant, $data) {
            // Serialize concurrent edits of one product's variants.
            CatalogReadyProduct::query()->whereKey($product->id)->lockForUpdate()->first();
            $variant->setRelation('product', $product);

            $default = (bool) ($data['is_default'] ?? false);
            $others = $product->variants()->when($variant->exists, fn ($q) => $q->whereKeyNot($variant->id));

            if (! $variant->exists && $product->isDisabled()) {
                throw ValidationException::withMessages(['product' => __('catalog.ready.error_disabled_product')]);
            }
            if ($default && (clone $others)->exists()) {
                throw ValidationException::withMessages(['is_default' => __('catalog.variant.error_default_alone')]);
            }
            if (! $default && (clone $others)->where('is_default', true)->exists()) {
                throw ValidationException::withMessages(['is_default' => __('catalog.variant.error_has_default')]);
            }

            return $this->writer->save(
                $actor, Permission::P12, $variant,
                [...$data, 'is_default' => $default],
                AuditAction::CatalogVariantCreated, AuditAction::CatalogVariantUpdated,
            );
        });
    }
}
