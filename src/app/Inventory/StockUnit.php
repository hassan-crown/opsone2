<?php

namespace App\Inventory;

/**
 * Q-43 (a): a fixed list of units a facility may enable; no automatic conversion between them (a piece is never
 * mixed with a metre). Each tracked item has exactly one unit, and every quantity of it is in thousandths of that unit.
 */
enum StockUnit: string
{
    case Piece = 'piece';
    case Meter = 'meter';
    case SquareMeter = 'square_meter';
    case LinearMeter = 'linear_meter';
    case Sheet = 'sheet';
    case Roll = 'roll';
    case Kilogram = 'kilogram';
    case Liter = 'liter';
    case Box = 'box';
    case Set = 'set';
    case Pair = 'pair';

    /**
     * Labels a material's free-text unit may already use (catalog P4), matched case-insensitively.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return match ($this) {
            self::Piece => ['piece', 'pcs', 'pc', 'unit', 'قطعة', 'حبة', 'وحدة'],
            self::Meter => ['meter', 'metre', 'm', 'متر', 'م'],
            self::SquareMeter => ['square_meter', 'square meter', 'sqm', 'm2', 'm²', 'متر مربع', 'م2', 'م²'],
            self::LinearMeter => ['linear_meter', 'linear meter', 'lm', 'متر طولي', 'م.ط'],
            self::Sheet => ['sheet', 'board', 'لوح', 'لوحة'],
            self::Roll => ['roll', 'لفة', 'رول'],
            self::Kilogram => ['kilogram', 'kg', 'كيلو', 'كيلوغرام', 'كجم'],
            self::Liter => ['liter', 'litre', 'l', 'لتر'],
            self::Box => ['box', 'carton', 'علبة', 'كرتون'],
            self::Set => ['set', 'طقم'],
            self::Pair => ['pair', 'زوج'],
        };
    }

    public static function fromLabel(?string $label): ?self
    {
        $needle = mb_strtolower(trim((string) $label));
        if ($needle === '') {
            return null;
        }
        foreach (self::cases() as $unit) {
            if (in_array($needle, $unit->aliases(), true)) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $u) => $u->value, self::cases());
    }
}
