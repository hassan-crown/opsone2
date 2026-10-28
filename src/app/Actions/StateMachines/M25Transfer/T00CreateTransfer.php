<?php

namespace App\Actions\StateMachines\M25Transfer;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Inventory\Quantity;
use App\Inventory\StockItems;
use App\Inventory\TransferStatus;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockTransfer;
use App\Models\User;
use App\Support\GaplessSequence;
use App\Support\LockingTransaction;
use Illuminate\Validation\ValidationException;

/**
 * M-25-T00: a draft transfer `ST-YYYY-000001` between two active locations the actor may move between (P-50 on both).
 * With `instant` it is received at once (M-25-T04: exit and arrival in one transaction).
 */
final class T00CreateTransfer
{
    public function __construct(
        private readonly GaplessSequence $sequence,
        private readonly TransitionRecorder $transitions,
        private readonly TransferMoves $moves,
    ) {}

    /**
     * @param  list<array{item_type: string, item_id: int, quantity_milli: int}>  $lines
     */
    public function handle(User $actor, int $fromId, int $toId, array $lines, ?string $note, bool $instant = false, string $source = TransitionRecorder::SOURCE_WEB): StockTransfer
    {
        return LockingTransaction::run(function () use ($actor, $fromId, $toId, $lines, $note, $instant, $source) {
            $from = StockLocation::query()->find($fromId);
            $to = StockLocation::query()->find($toId);
            if ($from === null || ! $from->isActive()) {
                throw ValidationException::withMessages(['from_location_id' => __('stock.validation.location_inactive')]);
            }
            if ($to === null || ! $to->isActive() || $to->id === $from->id) {
                throw ValidationException::withMessages(['to_location_id' => __('stock.validation.transfer_target')]);
            }
            Access::authorize($actor, Permission::P50, $from);
            Access::authorize($actor, Permission::P50, $to);
            if ($lines === []) {
                throw ValidationException::withMessages(['lines' => __('stock.validation.lines_required')]);
            }

            $transfer = StockTransfer::query()->create([
                'number' => $this->sequence->format('stock_transfers', 'ST', 6),
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'status' => TransferStatus::Draft,
                'note' => $note,
                'created_by' => $actor->id,
            ]);
            $seen = [];
            foreach ($lines as $i => $line) {
                $item = StockItems::resolve($line['item_type'], $line['item_id'], "lines.{$i}.item_id");
                if (isset($seen[$item->id])) {
                    throw ValidationException::withMessages(["lines.{$i}.item_id" => __('stock.validation.duplicate_item')]);
                }
                $seen[$item->id] = true;
                $transfer->lines()->create(['stock_item_id' => $item->id, 'quantity_milli' => $line['quantity_milli']]);
            }
            $this->transitions->record(StateMachine::M25, 'T00', $transfer, null, TransferStatus::Draft, $actor, $source);
            Audit::record(AuditAction::StockTransferCreated, $transfer, $actor, after: [
                'number' => $transfer->number, 'from' => $from->code, 'to' => $to->code,
                'lines' => array_map(fn (array $l) => $l['item_type'].':'.$l['item_id'].' × '.Quantity::format($l['quantity_milli']), $lines),
            ], source: $source, label: $transfer->number);

            if ($instant) {
                $transfer->load(['from', 'to']);
                $this->moves->lockAll($transfer);
                $this->moves->exit($actor, $transfer);
                $this->moves->arrive($actor, $transfer);
                $this->moves->finish($actor, $transfer, 'T04', TransferStatus::Draft, TransferStatus::Received, $source);
            }

            return $transfer;
        });
    }
}
