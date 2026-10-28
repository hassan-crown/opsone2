<?php

namespace App\Actions\StateMachines\M25Transfer;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Inventory\MovementKind;
use App\Inventory\StockLedger;
use App\Inventory\TransferStatus;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferLine;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Validation\ValidationException;

/**
 * Shared steps of the M-25 transitions: lock the transfer (P-50 over source and destination), check the source
 * state, and post the linked exit / arrival movements under balance locks taken in one global order.
 */
final class TransferMoves
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @param  list<TransferStatus>  $from
     */
    public function open(User $actor, StockTransfer $transfer, string $code, array $from, ?int $lockVersion): StockTransfer
    {
        $transfer = StockTransfer::query()->lockForUpdate()->with(['from', 'to'])->findOrFail($transfer->id);
        Access::authorize($actor, Permission::P50, $transfer);
        if ($lockVersion !== null && $transfer->lock_version !== $lockVersion) {
            throw new StaleRecord($transfer->lock_version);
        }
        if (! in_array($transfer->status, $from, true)) {
            throw new InvalidTransition(StateMachine::M25, $code, 'not_from_'.$transfer->status->value);
        }

        return $transfer;
    }

    public function exit(User $actor, StockTransfer $transfer): void
    {
        if (! $transfer->from->isActive()) {
            throw ValidationException::withMessages(['from_location_id' => __('stock.validation.location_inactive')]);
        }
        $lines = $transfer->lines()->get();
        $balances = $this->ledger->lockMany($lines->map(fn (StockTransferLine $l) => [$transfer->from_location_id, $l->stock_item_id])->all());
        foreach ($lines as $line) {
            $this->ledger->post(MovementKind::TransferOut, $balances[$transfer->from_location_id.':'.$line->stock_item_id], $line->quantity_milli, [
                'document_number' => $transfer->number, 'transfer_id' => $transfer->id, 'actor_id' => $actor->id,
            ], "lines.{$line->id}");
        }
    }

    public function arrive(User $actor, StockTransfer $transfer): void
    {
        if (! $transfer->to->isActive()) {
            throw ValidationException::withMessages(['to_location_id' => __('stock.validation.location_inactive')]);
        }
        $lines = $transfer->lines()->get();
        $balances = $this->ledger->lockMany($lines->map(fn (StockTransferLine $l) => [$transfer->to_location_id, $l->stock_item_id])->all());
        foreach ($lines as $line) {
            $this->ledger->post(MovementKind::TransferIn, $balances[$transfer->to_location_id.':'.$line->stock_item_id], $line->quantity_milli, [
                'document_number' => $transfer->number, 'transfer_id' => $transfer->id, 'actor_id' => $actor->id,
            ], "lines.{$line->id}");
        }
    }

    /**
     * Locks every balance the transfer touches (source and destination) in the global order, before any write.
     */
    public function lockAll(StockTransfer $transfer): void
    {
        $pairs = [];
        foreach ($transfer->lines()->get() as $line) {
            $pairs[] = [$transfer->from_location_id, $line->stock_item_id];
            $pairs[] = [$transfer->to_location_id, $line->stock_item_id];
        }
        $this->ledger->lockMany($pairs);
    }

    public function finish(User $actor, StockTransfer $transfer, string $code, TransferStatus $from, TransferStatus $to, string $source, ?string $reason = null): StockTransfer
    {
        $transfer->status = $to;
        if ($to === TransferStatus::InTransit || ($to === TransferStatus::Received && $transfer->dispatched_at === null)) {
            $transfer->dispatched_at = now();
        }
        if ($to === TransferStatus::Received) {
            $transfer->received_at = now();
        }
        $transfer->lock_version++;
        $transfer->save();
        $this->transitions->record(StateMachine::M25, $code, $transfer, $from, $to, $actor, $source, $reason);
        Audit::record(AuditAction::StockTransferMoved, $transfer, $actor, before: ['status' => $from->value],
            after: ['status' => $to->value, 'transition' => 'M-25-'.$code], reason: $reason, source: $source, label: $transfer->number);

        return $transfer;
    }
}
