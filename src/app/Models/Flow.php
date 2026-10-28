<?php

namespace App\Models;

use App\Enums\FlowStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The facility's single custom flow (M-23, WF-01). Changes only through the M-23 actions.
 *
 * @property int $id
 * @property FlowStatus $status
 * @property int|null $published_version_id
 * @property string $template_key
 * @property array{stages: list<array<string, mixed>>}|null $draft
 * @property int|null $draft_by
 * @property CarbonInterface|null $draft_updated_at
 * @property int $lock_version
 * @property-read FlowVersion|null $publishedVersion
 */
class Flow extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    public static function current(): ?self
    {
        return self::query()->orderBy('id')->first();
    }

    /**
     * @return BelongsTo<FlowVersion, $this>
     */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'published_version_id');
    }

    /**
     * @return HasMany<FlowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class)->orderBy('number');
    }

    protected function casts(): array
    {
        return [
            'status' => FlowStatus::class,
            'draft' => 'array',
            'draft_updated_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
