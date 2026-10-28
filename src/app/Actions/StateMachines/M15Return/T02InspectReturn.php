<?php

namespace App\Actions\StateMachines\M15Return;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ReturnStatus;
use App\Enums\StateMachine;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnPart;
use App\Models\User;
use App\Returns\Contracts\SettlementFollowUp;
use App\Returns\ReturnInspection;
use App\Returns\ReturnLock;
use App\Returns\ReturnNotices;
use App\Returns\ReturnProgress;
use App\Returns\ReturnStock;
use App\Returns\ReturnWrite;

/**
 * M-15-T02 inspect (P-53 on the order and each part's location, FUL-06/INT-02): per received part, once, how much is
 * good (back to the location's available stock), damaged (custody, never available) or rejected (handed back). The
 * result never decides the customer's money: damaged goods are not refused a refund by the classification. The
 * return becomes `inspected` when everything accepted was received and inspected (else stays `received`, T07).
 */
final class T02InspectReturn
{
    public function __construct(
        private readonly ReturnWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly ReturnStock $stock,
        private readonly ReturnInspection $inspection,
    ) {}

    /**
     * @param  list<array{part_id: int, good_milli: int, damaged_milli: int, rejected_milli: int}>  $results
     */
    public function handle(User $actor, OrderReturn $return, array $results, ?string $note, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): OrderReturn
    {
        $payload = ['return' => $return->id, 'results' => $results, 'note' => $note, 'lock_version' => $lockVersion];

        $this->write->run('inspect', $actor, $idempotencyKey, $payload, function () use ($actor, $return, $results, $note, $lockVersion, $source) {
            $locked = ReturnLock::lock($return, $lockVersion, 'T02');
            Access::authorize($actor, Permission::P53, $locked);
            if ($results === []) {
                throw new InvalidTransition(StateMachine::M15, 'T02', 'nothing_to_inspect');
            }
            $parts = [];
            foreach ($results as $i => $row) {
                $part = OrderReturnPart::query()->with(['line.orderLine', 'location'])->where('order_return_id', $locked->id)->lockForUpdate()->find((int) $row['part_id']);
                if ($part === null || isset($parts[$part->id])) {
                    throw new InvalidTransition(StateMachine::M15, 'T02', 'unknown_part');
                }
                $parts[$part->id] = [$part, $row, $i];
            }
            $this->stock->lockFor(array_map(fn (array $p) => $p[0], $parts));
            foreach ($parts as [$part, $row, $i]) {
                $this->inspection->apply($actor, $locked, $part, [
                    'good_milli' => (int) $row['good_milli'], 'damaged_milli' => (int) $row['damaged_milli'], 'rejected_milli' => (int) $row['rejected_milli'],
                ], $note, 'results.'.$i, 'T02');
            }
            self::finish($this->transitions, $locked, $locked->status, array_map(fn (array $p) => $p[0], $parts), $actor, $source, $note);

            return ['return_id' => $locked->id];
        });

        return $return->refresh();
    }

    /**
     * Records T02 with the outcome, moves the return to its derived state, audits, and — when the inspection closes the
     * goods side with money still to settle — opens the settlement follow-up and tells P-42.
     *
     * @param  array<array-key, OrderReturnPart>  $parts
     */
    public static function finish(TransitionRecorder $transitions, OrderReturn $return, ReturnStatus $from, array $parts, User $actor, string $source, ?string $note): void
    {
        $to = ReturnProgress::derive($return);
        ReturnLock::bump($return, $to);
        $outcome = array_map(fn (OrderReturnPart $p) => ['part' => $p->id, 'good_milli' => $p->good_milli, 'damaged_milli' => $p->damaged_milli, 'rejected_milli' => $p->rejected_milli], array_values($parts));
        $transitions->record(StateMachine::M15, 'T02', $return, $from, $to, $actor, $source, $note, ['number' => $return->number, 'parts' => $outcome]);
        Audit::record(AuditAction::ReturnInspected, $return, $actor, after: ['number' => $return->number, 'parts' => $outcome, 'status' => $to->value], source: $source, branchIds: [$return->order->branch_id], label: $return->number);
        if ($to === ReturnStatus::Inspected && (int) $return->settlement_minor > 0 && $return->refund_id === null) {
            app(SettlementFollowUp::class)->open($return->order, 'order_return', $return->id, $actor);
            app(ReturnNotices::class)->later('return.inspected', $return, Permission::P42, $actor);
        }
    }
}
