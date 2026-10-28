<?php

namespace App\Actions\StateMachines\M15Return;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Inventory\MovementKind;
use App\Inventory\Quantity;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnPart;
use App\Models\User;
use App\Returns\ReturnLock;
use App\Returns\ReturnStock;
use App\Returns\ReturnWrite;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * M-15-T04 correct an inspection mistake (P-53 with P-49 on the part's location, since stock moves): a reason and a
 * counter movement, never an edit of the past one. Good → damaged takes the quantity out of the available stock
 * (`return_reclass`, refused when it is no longer there) and records it in custody; damaged → good posts it back
 * (`return_in`). The received and rejected quantities and any money decision stay as they are.
 */
final class T04CorrectInspection
{
    public function __construct(
        private readonly ReturnWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly ReturnStock $stock,
    ) {}

    public function handle(User $actor, OrderReturn $return, int $partId, int $goodMilli, string $reason, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): OrderReturn
    {
        $payload = ['return' => $return->id, 'part' => $partId, 'good' => $goodMilli, 'reason' => $reason, 'lock_version' => $lockVersion];

        $this->write->run('correct', $actor, $idempotencyKey, $payload, function () use ($actor, $return, $partId, $goodMilli, $reason, $lockVersion, $source) {
            $locked = ReturnLock::lock($return, $lockVersion, 'T04');
            Access::authorize($actor, Permission::P53, $locked);
            $part = OrderReturnPart::query()->with(['line.orderLine', 'location'])->where('order_return_id', $locked->id)->lockForUpdate()->find($partId);
            if ($part === null || ! $part->isInspected()) {
                throw new InvalidTransition(StateMachine::M15, 'T04', 'not_inspected');
            }
            Access::authorize($actor, Permission::P53, $part->location);
            Access::authorize($actor, Permission::P49, $part->location);
            $reason = T00RequestReturn::reason($reason, 'reason');
            $kept = (int) $part->good_milli + (int) $part->damaged_milli;
            if ($goodMilli < 0 || $goodMilli > $kept) {
                throw ValidationException::withMessages(['good' => __('returns.error.inspection_sum', ['received' => Quantity::format($kept)])]);
            }
            $delta = $goodMilli - (int) $part->good_milli;
            if ($delta === 0) {
                throw new InvalidTransition(StateMachine::M15, 'T04', 'nothing_changed');
            }
            $before = ['good_milli' => $part->good_milli, 'damaged_milli' => $part->damaged_milli];
            $this->stock->lockFor([$part]);
            $tag = 'return:'.$part->id.':fix:'.Str::uuid()->toString();
            if ($delta > 0) {
                $this->stock->post(MovementKind::ReturnIn, $locked, $part, $delta, $actor, $tag, $reason);
            } else {
                $this->stock->post(MovementKind::ReturnReclass, $locked, $part, -$delta, $actor, $tag.':out', $reason);
                $this->stock->post(MovementKind::ReturnDamaged, $locked, $part, -$delta, $actor, $tag.':custody', $reason);
            }
            $part->forceFill(['good_milli' => $goodMilli, 'damaged_milli' => $kept - $goodMilli])->save();
            ReturnLock::bump($locked, $locked->status);

            $after = ['good_milli' => $part->good_milli, 'damaged_milli' => $part->damaged_milli];
            $this->transitions->record(StateMachine::M15, 'T04', $locked, $locked->status, $locked->status, $actor, $source, $reason, ['number' => $locked->number, 'part' => $part->id, 'before' => $before, 'after' => $after]);
            Audit::record(AuditAction::ReturnCorrected, $locked, $actor, before: $before, after: ['number' => $locked->number, 'part' => $part->id, ...$after], reason: $reason, source: $source, branchIds: [$locked->order->branch_id], label: $locked->number);

            return ['return_id' => $locked->id];
        });

        return $return->refresh();
    }
}
