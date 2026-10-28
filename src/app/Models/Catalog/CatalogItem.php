<?php

namespace App\Models\Catalog;

use App\Access\Contracts\ScopedRecord;
use App\Catalog\CatalogStatus;
use App\Models\Attachment;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * A catalog row written through App\Catalog\CatalogWriter (see the CatalogRecord trait).
 *
 * @property int $current_version
 * @property CatalogStatus $status
 * @property CarbonInterface|null $disabled_at
 * @property Translated $name
 */
interface CatalogItem extends ScopedRecord
{
    /**
     * @return array<string, mixed>
     */
    public function versionPayload(): array;

    /**
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array;

    public function isSelectable(): bool;

    public function catalogStatus(): CatalogStatus;

    /**
     * Moves the row to a status (setting or clearing `disabled_at`); the caller saves.
     */
    public function changeStatus(CatalogStatus $to): void;

    public function versionNumber(): int;

    /**
     * Starts the next version number; the caller saves and appends the version row.
     */
    public function nextVersion(): void;

    /**
     * Human label for the audit trail.
     */
    public function displayName(): string;

    /**
     * Newest first.
     *
     * @return Collection<int, CatalogVersion>
     */
    public function recentVersions(int $limit): Collection;

    /**
     * @return Collection<int, Attachment>
     */
    public function imageFiles(): Collection;
}
