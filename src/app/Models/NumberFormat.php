<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The facility's format for one document sequence (ADR-0037): prefix, year (Gregorian, Hijri or none), optional
 * branch segment with a counter per branch, digits and a starting number for the period it was set in.
 *
 * @property int $id
 * @property string $sequence
 * @property string $prefix
 * @property string $year_mode gregorian | hijri | none
 * @property bool $per_branch
 * @property int $pad
 * @property int|null $start_at
 * @property int|null $start_period
 * @property int|null $updated_by
 */
class NumberFormat extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'per_branch' => 'boolean',
            'pad' => 'integer',
            'start_at' => 'integer',
            'start_period' => 'integer',
        ];
    }
}
