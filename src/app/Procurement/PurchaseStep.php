<?php

namespace App\Procurement;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Enums\PurchaseStatus;
use App\Enums\StateMachine;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Support\Collection;

/**
 * Shared steps of an M-13 transition: lock the order row (every line write goes through it, so concurrent receipts and
 * cancellations queue), authorize the transition's permission on it, check the version the screen read and that the
 * transition leaves from the current state.
 */
final class PurchaseStep
{
    public static function lock(User $actor, PurchaseOrder $po, Permission $permission, ?int $lockVersion, string $code): PurchaseOrder
    {
        $fresh = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
        Access::authorize($actor, $permission, $fresh);
        if ($lockVersion !== null && $fresh->lock_version !== $lockVersion) {
            throw new StaleRecord($fresh->lock_version);
        }
        if (! in_array($fresh->status, PurchaseStatus::transitions()[$code]['from'], true)) {
            throw new InvalidTransition(StateMachine::M13, $code, 'not_from_'.$fresh->status->value);
        }
        $fresh->load('lines');

        return $fresh;
    }

    /**
     * The state the lines imply for an open order: all accepted → received; something arrived → partially received.
     *
     * @param  Collection<int, PurchaseOrderLine>  $lines
     */
    public static function derive(Collection $lines): PurchaseStatus
    {
        if ($lines->isNotEmpty() && $lines->every(fn ($line) => $line->remainingMilli() === 0)) {
            return PurchaseStatus::Received;
        }

        return $lines->contains(fn ($line) => $line->received_milli > 0) ? PurchaseStatus::PartiallyReceived : PurchaseStatus::Ordered;
    }

    /**
     * Saves the new state and bumps the version.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function move(PurchaseOrder $po, PurchaseStatus $to, array $fields = []): PurchaseStatus
    {
        $from = $po->status;
        $po->forceFill([...$fields, 'status' => $to, 'lock_version' => $po->lock_version + 1])->save();

        return $from;
    }
}
