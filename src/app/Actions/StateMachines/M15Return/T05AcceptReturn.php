<?php

namespace App\Actions\StateMachines\M15Return;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Commissions\CommissionAccrual;
use App\Enums\ReturnStatus;
use App\Enums\StateMachine;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnLine;
use App\Models\User;
use App\Returns\Contracts\SettlementFollowUp;
use App\Returns\ReturnEligibility;
use App\Returns\ReturnLock;
use App\Returns\ReturnNotices;
use App\Returns\ReturnProgress;
use App\Returns\ReturnWrite;
use Illuminate\Validation\ValidationException;

/**
 * M-15-T05 accept (P-102, INT-02/03): the accepted quantity per line (≤ requested), the reason and the expected
 * settlement (≤ the value of the accepted goods as sold; the value when omitted). Under the order lock the accepted
 * quantities are checked against what is still eligible — delivered minus what other accepted returns hold — and then
 * hold it: two returns of the same delivered 2 can never accept 3 (scenario 7). No stock moves and no money is
 * refunded here; the order's commission is re-derived through the 6d hook (`return:{id}`).
 */
final class T05AcceptReturn
{
    public function __construct(
        private readonly ReturnWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly CommissionAccrual $commission,
        private readonly SettlementFollowUp $followUp,
        private readonly ReturnNotices $notices,
    ) {}

    /**
     * @param  list<array{order_line_id: int, quantity_milli: int}>|null  $lines  null = everything requested
     */
    public function handle(User $actor, OrderReturn $return, ?array $lines, string $reason, ?int $settlementMinor, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): OrderReturn
    {
        $payload = ['return' => $return->id, 'lines' => $lines, 'reason' => $reason, 'settlement' => $settlementMinor, 'lock_version' => $lockVersion];

        $this->write->run('accept', $actor, $idempotencyKey, $payload, function () use ($actor, $return, $lines, $reason, $settlementMinor, $lockVersion, $source) {
            $locked = ReturnLock::lock($return, $lockVersion, 'T05');
            Access::authorize($actor, Permission::P102, $locked);
            // A settlement other than the value is a money figure: only who may see the order's amounts sets it.
            if ($settlementMinor !== null) {
                Access::authorize($actor, Permission::P37, $locked->order);
            }
            $reason = T00RequestReturn::reason($reason, 'reason');

            $own = OrderReturnLine::query()->where('order_return_id', $locked->id)->get()->keyBy('order_line_id');
            $eligible = ReturnEligibility::lines($locked->order, $locked->id);
            $lines ??= $own->map(fn (OrderReturnLine $l) => ['order_line_id' => $l->order_line_id, 'quantity_milli' => $l->requested_milli])->values()->all();
            $requested = $own->map(fn (OrderReturnLine $l) => ['requested' => $l->requested_milli])->all();
            $picked = T00RequestReturn::pick(array_values($lines), $requested, 'requested');
            foreach ($picked as $lineId => $milli) {
                if ($milli > ($eligible[$lineId]['eligible'] ?? 0)) {
                    throw new InvalidTransition(StateMachine::M15, 'T05', 'over_delivered');
                }
            }
            foreach ($own as $lineId => $line) {
                $line->forceFill(['accepted_milli' => $picked[$lineId] ?? 0])->save();
            }

            $value = ReturnProgress::value($locked);
            if ($settlementMinor !== null && ($settlementMinor < 0 || ($value !== null && $settlementMinor > $value))) {
                throw ValidationException::withMessages(['settlement' => __('returns.error.settlement_above_value')]);
            }
            $settlement = $settlementMinor ?? $value;
            $locked->forceFill(['decision_reason' => $reason, 'decided_by' => $actor->id, 'decided_at' => now(), 'value_minor' => $value, 'settlement_minor' => $settlement])->save();
            ReturnLock::bump($locked, ReturnStatus::Accepted);

            $this->transitions->record(StateMachine::M15, 'T05', $locked, ReturnStatus::Requested, ReturnStatus::Accepted, $actor, $source, $reason, [
                'number' => $locked->number, 'lines' => array_map(fn ($id, $milli) => ['order_line_id' => $id, 'quantity_milli' => $milli], array_keys($picked), $picked),
            ]);
            Audit::record(AuditAction::ReturnAccepted, $locked, $actor, after: ['number' => $locked->number, 'lines' => count($picked), 'settlement_minor' => $settlement], reason: $reason, source: $source, branchIds: [$locked->order->branch_id], label: $locked->number);
            $this->commission->recompute($locked->order, 'return:'.$locked->id, $actor, $reason);
            if ((int) $settlement > 0) {
                $this->followUp->open($locked->order, 'order_return', $locked->id, $actor);
            }
            $this->notices->later('return.accepted', $locked, Permission::P53, $actor);

            return ['return_id' => $locked->id];
        });

        return $return->refresh();
    }
}
