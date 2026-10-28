<?php

namespace App\Actions\Inventory;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Inventory\CountStatus;
use App\Inventory\StockAccess;
use App\Inventory\StockConflict;
use App\Inventory\StockItems;
use App\Inventory\StockLedger;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockCount;
use App\Models\Inventory\StockCountLine;
use App\Models\Inventory\StockLocation;
use App\Models\User;
use App\Support\GaplessSequence;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use App\Workflow\WorkGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Stock counts (FUL-04): whoever sees a location's history may count it (`SC-YYYY-000001`) and submit; a P-49 holder
 * covering the location approves — posting each difference against the on-hand at approval time as an adjustment
 * (never below what is reserved) — or rejects with a note. Nothing changes a balance before approval.
 */
final class StockCounts
{
    public function __construct(
        private readonly GaplessSequence $sequence,
        private readonly StockLedger $ledger,
        private readonly AdjustStock $adjust,
    ) {}

    /**
     * @param  list<array{item_type: string, item_id: int, counted_milli: int}>  $lines
     */
    public function create(User $actor, StockLocation $location, string $reason, array $lines, string $source = TransitionRecorder::SOURCE_WEB): StockCount
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $location, $reason, $lines, $source) {
            if (! StockAccess::canSeeHistory($actor, $location)) {
                throw new AuthorizationException(__('access.denied.generic'));
            }
            if (! $location->isActive()) {
                throw ValidationException::withMessages(['location_id' => __('stock.validation.location_inactive')]);
            }
            $count = StockCount::query()->create([
                'number' => $this->sequence->format('stock_counts', 'SC', 6),
                'location_id' => $location->id,
                'status' => CountStatus::Draft,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);
            $this->writeLines($count, $lines);
            Audit::record(AuditAction::StockCountRecorded, $count, $actor, after: ['number' => $count->number, 'status' => CountStatus::Draft->value, 'lines' => count($lines)], reason: $reason, source: $source, label: $count->number);

            return $count;
        });
    }

    /**
     * @param  list<array{item_type: string, item_id: int, counted_milli: int}>  $lines
     */
    public function update(User $actor, StockCount $count, array $lines, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): StockCount
    {
        return LockingTransaction::run(function () use ($actor, $count, $lines, $lockVersion, $source) {
            $count = $this->open($count, $lockVersion, [CountStatus::Draft]);
            if (! StockAccess::canSeeHistory($actor, $count->location)) {
                throw new AuthorizationException(__('access.denied.generic'));
            }
            $this->writeLines($count, $lines);
            $count->lock_version++;
            $count->save();
            Audit::record(AuditAction::StockCountRecorded, $count, $actor, after: ['number' => $count->number, 'status' => 'draft', 'lines' => count($lines)], source: $source, label: $count->number);

            return $count;
        });
    }

    public function submit(User $actor, StockCount $count, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): StockCount
    {
        return LockingTransaction::run(function () use ($actor, $count, $lockVersion, $source) {
            $count = $this->open($count, $lockVersion, [CountStatus::Draft]);
            if (! StockAccess::canSeeHistory($actor, $count->location)) {
                throw new AuthorizationException(__('access.denied.generic'));
            }
            if ($count->lines()->doesntExist()) {
                throw ValidationException::withMessages(['lines' => __('stock.validation.lines_required')]);
            }

            return $this->decide($actor, $count, CountStatus::Submitted, null, $source);
        });
    }

    public function approve(User $actor, StockCount $count, ?int $lockVersion, ?string $note = null, string $source = TransitionRecorder::SOURCE_WEB): StockCount
    {
        return LockingTransaction::run(function () use ($actor, $count, $lockVersion, $note, $source) {
            $count = $this->open($count, $lockVersion, [CountStatus::Submitted]);
            Access::authorize($actor, Permission::P49, $count->location);
            $lines = $count->lines()->with('item')->get();
            $this->ledger->lockMany($lines->map(fn (StockCountLine $l) => [$count->location_id, $l->stock_item_id])->all());
            foreach ($lines as $line) {
                $onHand = $this->ledger->lock($count->location_id, $line->stock_item_id)->on_hand_milli;
                $diff = $line->counted_milli - $onHand;
                if ($diff === 0) {
                    continue;
                }
                try {
                    $this->adjust->post($actor, $count->location, $line->item->item_type, $line->item->item_id, $diff > 0 ? 'in' : 'out', abs($diff),
                        $count->number.' — '.$count->reason, $count->id, $source);
                } catch (ValidationException) {
                    throw ValidationException::withMessages(["lines.{$line->id}" => __('stock.validation.count_below_reserved')]);
                }
            }

            return $this->decide($actor, $count, CountStatus::Approved, $note, $source);
        });
    }

    public function reject(User $actor, StockCount $count, ?int $lockVersion, string $note, string $source = TransitionRecorder::SOURCE_WEB): StockCount
    {
        $note = WorkGuard::reason($note, 'note');

        return LockingTransaction::run(function () use ($actor, $count, $lockVersion, $note, $source) {
            $count = $this->open($count, $lockVersion, [CountStatus::Submitted]);
            Access::authorize($actor, Permission::P49, $count->location);

            return $this->decide($actor, $count, CountStatus::Rejected, $note, $source);
        });
    }

    /**
     * @param  list<CountStatus>  $from
     */
    private function open(StockCount $count, ?int $lockVersion, array $from): StockCount
    {
        $count = StockCount::query()->lockForUpdate()->with('location')->findOrFail($count->id);
        if ($lockVersion !== null && $count->lock_version !== $lockVersion) {
            throw new StaleRecord($count->lock_version);
        }
        if (! in_array($count->status, $from, true)) {
            throw new StockConflict('not_from_'.$count->status->value);
        }

        return $count;
    }

    private function decide(User $actor, StockCount $count, CountStatus $to, ?string $note, string $source): StockCount
    {
        $from = $count->status;
        $count->status = $to;
        if ($to !== CountStatus::Submitted) {
            $count->decided_by = $actor->id;
            $count->decided_at = now();
            $count->decision_note = $note;
        }
        $count->lock_version++;
        $count->save();
        Audit::record(AuditAction::StockCountRecorded, $count, $actor, before: ['status' => $from->value], after: ['number' => $count->number, 'status' => $to->value], reason: $note, source: $source, label: $count->number);

        return $count;
    }

    /**
     * @param  list<array{item_type: string, item_id: int, counted_milli: int}>  $lines
     */
    private function writeLines(StockCount $count, array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('stock.validation.lines_required')]);
        }
        $count->lines()->delete();
        $seen = [];
        foreach ($lines as $i => $line) {
            $item = StockItems::resolve($line['item_type'], $line['item_id'], "lines.{$i}.item_id");
            if (isset($seen[$item->id])) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => __('stock.validation.duplicate_item')]);
            }
            $seen[$item->id] = true;
            $onHand = (int) (StockBalance::query()->where('location_id', $count->location_id)->where('stock_item_id', $item->id)->value('on_hand_milli'));
            $count->lines()->create(['stock_item_id' => $item->id, 'expected_milli' => $onHand, 'counted_milli' => $line['counted_milli']]);
        }
    }
}
