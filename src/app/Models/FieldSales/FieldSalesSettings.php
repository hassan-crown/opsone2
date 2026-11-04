<?php

namespace App\Models\FieldSales;

use Illuminate\Database\Eloquent\Model;

/**
 * The field-sales settings DRAFT (ADR-0041): one row; the application reads the published configuration release
 * (`App\FieldSales\FieldSalesConfig`), never this row.
 *
 * @property int $id
 * @property bool $enabled
 * @property list<string> $kinds
 * @property int|null $response_hours
 * @property int $lock_version
 * @property int|null $updated_by
 */
class FieldSalesSettings extends Model
{
    /** Appointment kinds a rep may handle in the field app. */
    public const KINDS = ['visit', 'measurement'];

    public const DEFAULTS = ['enabled' => false, 'kinds' => self::KINDS, 'response_hours' => 24];

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    public static function working(): self
    {
        return self::query()->orderBy('id')->first() ?? new self(self::DEFAULTS);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'kinds' => 'array', 'response_hours' => 'integer'];
    }
}
