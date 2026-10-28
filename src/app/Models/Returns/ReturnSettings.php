<?php

namespace App\Models\Returns;

use Illuminate\Database\Eloquent\Model;

/**
 * Facility return settings (P-02): the ready-goods return window in days, an indicator for the decision only.
 *
 * @property int $id
 * @property int $window_days
 * @property int|null $updated_by
 */
class ReturnSettings extends Model
{
    public const DEFAULT_WINDOW_DAYS = 14;

    protected $guarded = ['id'];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self(['window_days' => self::DEFAULT_WINDOW_DAYS]);
    }

    protected function casts(): array
    {
        return ['window_days' => 'integer'];
    }
}
