<?php

namespace App\Models\Commissions;

use Illuminate\Database\Eloquent\Model;

/**
 * Facility commission policy (SAL-10, SAL-19): off with 0% until someone holding P-95 sets it. One row; current()
 * falls back to the defaults. A change applies to sales pinned from now on — a pinned sale keeps its rate.
 *
 * @property bool $enabled
 * @property int $default_rate_bp
 * @property bool $manual_lines_eligible
 * @property int|null $updated_by
 * @property int $lock_version
 */
class CommissionSettings extends Model
{
    public const DEFAULTS = [
        'enabled' => false,
        'default_rate_bp' => 0,
        'manual_lines_eligible' => true,
    ];

    protected $table = 'commission_settings';

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self(self::DEFAULTS);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'default_rate_bp' => 'integer',
            'manual_lines_eligible' => 'boolean',
            'lock_version' => 'integer',
        ];
    }
}
