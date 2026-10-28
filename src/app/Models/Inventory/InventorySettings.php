<?php

namespace App\Models\Inventory;

use App\Inventory\StockUnit;
use Illuminate\Database\Eloquent\Model;

/**
 * Facility inventory settings (one row): enabled units (Q-43 a) and whether low-stock alerts notify.
 *
 * @property int $id
 * @property list<string> $enabled_units
 * @property bool $low_stock_alerts
 * @property int $lock_version
 */
class InventorySettings extends Model
{
    protected $table = 'inventory_settings';

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first()
            ?? self::query()->create(['enabled_units' => [StockUnit::Piece->value], 'low_stock_alerts' => true]);
    }

    public function enables(StockUnit $unit): bool
    {
        return $unit === StockUnit::Piece || in_array($unit->value, $this->enabled_units, true);
    }

    protected function casts(): array
    {
        return ['enabled_units' => 'array', 'low_stock_alerts' => 'boolean', 'lock_version' => 'integer'];
    }
}
