<?php

namespace App\Catalog;

use App\Files\AttachmentService;
use App\Models\Catalog\CatalogItem;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\User;
use App\Support\Translatable\Translated;

/**
 * Shapes catalog rows for the screens. Bilingual texts go out whole (`{ar,en}`) so forms can edit both, with the
 * reader's `label` already resolved (fallback to the other language). Money leaves as integer minor units.
 */
final class CatalogPresenter
{
    public function __construct(private readonly AttachmentService $files) {}

    /**
     * @return array{ar: string|null, en: string|null, label: string}
     */
    public static function text(Translated $text): array
    {
        return [...$text->toArray(), 'label' => (string) $text->get()];
    }

    /**
     * @return array<string, mixed>
     */
    public function productRow(CatalogProduct $p): array
    {
        return [
            'id' => $p->id,
            'name' => self::text($p->name),
            'category' => $p->category,
            'pricing_basis' => $p->pricing_basis->value,
            'reference_price_minor' => $p->reference_price_minor,
            'groups' => count($p->option_groups),
            'status' => $p->status->value,
            'version' => $p->current_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function product(CatalogProduct $p, User $viewer): array
    {
        return [
            ...$this->productRow($p),
            'description' => self::text($p->description),
            'option_groups' => $p->option_groups,
            'images' => $this->images($p, $viewer),
            'versions' => $this->versions($p),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function materialRow(CatalogMaterial $m): array
    {
        return [
            'id' => $m->id,
            'name' => self::text($m->name),
            'description' => self::text($m->description),
            'group' => $m->material_group,
            'unit' => $m->unit,
            'availability' => $m->availability->value,
            'supplier_name' => $m->supplier_name,
            'track_quantity' => $m->track_quantity,
            'status' => $m->status->value,
            'version' => $m->current_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function material(CatalogMaterial $m, User $viewer): array
    {
        return [...$this->materialRow($m), 'images' => $this->images($m, $viewer)];
    }

    /**
     * @return array<string, mixed>
     */
    public function readyRow(CatalogReadyProduct $p): array
    {
        return [
            'id' => $p->id,
            'name' => self::text($p->name),
            'status' => $p->status->value,
            'variants' => (int) ($p->variants_count ?? $p->variants()->count()),
            'version' => $p->current_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ready(CatalogReadyProduct $p, User $viewer): array
    {
        return [
            ...$this->readyRow($p),
            'description' => self::text($p->description),
            'images' => $this->images($p, $viewer),
            'versions' => $this->versions($p),
            'variants_list' => $p->variants()->with('product')->get()->map(fn (CatalogReadyVariant $v) => $this->variant($v))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function variant(CatalogReadyVariant $v): array
    {
        return [
            'id' => $v->id,
            'code' => $v->code,
            'name' => self::text($v->name),
            'price_minor' => $v->price_minor,
            'is_default' => $v->is_default,
            'status' => $v->status->value,
            'version' => $v->current_version,
            'versions' => $this->versions($v),
        ];
    }

    /**
     * @return list<array{uuid: string, name: string, url: string}>
     */
    private function images(CatalogItem $owner, User $viewer): array
    {
        $out = [];
        foreach ($owner->imageFiles() as $file) {
            $out[] = ['uuid' => $file->uuid, 'name' => $file->original_name, 'url' => $this->files->downloadUrl($file, $viewer)];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, version: int, created_at: string, author: string|null, payload: array<string, mixed>}>
     */
    private function versions(CatalogItem $record): array
    {
        $out = [];
        foreach ($record->recentVersions(20) as $v) {
            $out[] = [
                'id' => $v->id,
                'version' => $v->version,
                'created_at' => $v->created_at->toIso8601String(),
                'author' => $v->author?->name,
                'payload' => $v->payload,
            ];
        }

        return $out;
    }
}
