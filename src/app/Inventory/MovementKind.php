<?php

namespace App\Inventory;

/**
 * Kinds of the append-only stock journal and their effect on the balance (on hand, reserved). A quantity is always
 * positive; the direction comes from the kind. A wrong movement is answered by a linked opposite one, never deleted.
 */
enum MovementKind: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case Reserve = 'reserve';
    case Release = 'release';
    case IssueReserved = 'issue_reserved';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    // P7 returns (ADR-0019): inspected good goods back to stock; damaged goods recorded in custody (no balance effect,
    // never available); a correction of good into damaged takes it out of the free quantity again.
    case ReturnIn = 'return_in';
    case ReturnDamaged = 'return_damaged';
    case ReturnReclass = 'return_reclass';

    /**
     * @return array{0: int, 1: int} signs of (on hand, reserved)
     */
    public function signs(): array
    {
        return match ($this) {
            self::Receipt, self::TransferIn, self::AdjustmentIn, self::ReturnIn => [1, 0],
            self::Issue, self::TransferOut, self::AdjustmentOut, self::ReturnReclass => [-1, 0],
            self::Reserve => [0, 1],
            self::Release => [0, -1],
            self::IssueReserved => [-1, -1],
            self::ReturnDamaged => [0, 0],
        };
    }

    /**
     * Whether the movement takes from the free quantity (on hand − reserved); those need it to be available.
     */
    public function takesAvailable(): bool
    {
        return in_array($this, [self::Issue, self::TransferOut, self::AdjustmentOut, self::Reserve, self::ReturnReclass], true);
    }
}
