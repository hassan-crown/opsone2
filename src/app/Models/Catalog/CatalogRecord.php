<?php

namespace App\Models\Catalog;

use App\Catalog\CatalogStatus;
use App\Models\Attachment;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared behavior of catalog rows: facility-wide scope (the catalog belongs to no branch), an append-only version
 * history, attachments (images) and the soft-disable lifecycle.
 *
 * @property CatalogStatus $status
 * @property int $current_version
 * @property CarbonInterface|null $disabled_at
 * @property Translated $name
 */
trait CatalogRecord
{
    /**
     * Facts snapshotted into a version (what a later order line may freeze).
     *
     * @return array<string, mixed>
     */
    abstract public function versionPayload(): array;

    /**
     * What the audit trail records: the versioned facts, plus internal fields a version leaves out.
     *
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return $this->versionPayload();
    }

    /**
     * @return MorphMany<CatalogVersion, $this>
     */
    public function versions(): MorphMany
    {
        return $this->morphMany(CatalogVersion::class, 'versionable')->orderByDesc('version');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->chaperone()->orderBy('id');
    }

    /**
     * Rows that can be chosen on new work; disabled and draft rows stay for past usage only.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSelectable(Builder $query): void
    {
        $query->whereIn('status', [CatalogStatus::Active->value, CatalogStatus::Published->value]);
    }

    public function recentVersions(int $limit): Collection
    {
        return $this->versions()->with('author')->limit($limit)->get();
    }

    public function imageFiles(): Collection
    {
        return $this->attachments()->get();
    }

    public function catalogStatus(): CatalogStatus
    {
        return $this->status;
    }

    public function changeStatus(CatalogStatus $to): void
    {
        $this->status = $to;
        $this->disabled_at = $to === CatalogStatus::Disabled ? now() : null;
    }

    public function versionNumber(): int
    {
        return $this->current_version;
    }

    public function nextVersion(): void
    {
        $this->current_version++;
    }

    public function displayName(): string
    {
        $code = $this->getAttribute('code');
        $name = (string) ($this->name->only('ar') ?? $this->name->only('en') ?? '');

        return trim($name.(is_string($code) && $code !== '' ? ' ['.$code.']' : ''));
    }

    public function isSelectable(): bool
    {
        return $this->status->isSelectable();
    }

    public function isDisabled(): bool
    {
        return $this->status === CatalogStatus::Disabled;
    }

    public function accessBranchIds(): array
    {
        return [];
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return [];
    }

    public function accessOwnerId(): ?int
    {
        return null;
    }
}
