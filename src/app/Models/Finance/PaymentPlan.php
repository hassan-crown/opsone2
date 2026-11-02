<?php

namespace App\Models\Finance;

use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A facility payment plan (ADR-0037): ordered lines with a basis (percent in basis points, amount in halalas or the
 * rest), a due trigger from the closed catalogue, offset days and grace days. One active plan is the default. Orders
 * copy the lines at the agreement (OrderSchedule::sync), so later edits never change an existing order.
 *
 * @property int $id
 * @property string $key
 * @property Translated $name
 * @property list<array{key: string, name: array{ar: string, en?: string|null}, basis: string, value: int|null, event: string, stage_key: string|null, offset_days: int, grace_days: int}> $lines
 * @property bool $is_default
 * @property CarbonInterface|null $archived_at
 * @property int $lock_version
 * @property int|null $updated_by
 */
class PaymentPlan extends Model
{
    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1, 'is_default' => false];

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    protected function casts(): array
    {
        return [
            'name' => TranslatableString::class,
            'lines' => 'array',
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
