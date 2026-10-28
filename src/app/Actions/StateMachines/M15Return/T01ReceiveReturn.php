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
use App\Inventory\OrderStock;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnLine;
use App\Models\Returns\OrderReturnPart;
use App\Models\User;
use App\Returns\ReturnInspection;
use App\Returns\ReturnLock;
use App\Returns\ReturnProgress;
use App\Returns\ReturnStock;
use App\Returns\ReturnWrite;
use Illuminate\Validation\ValidationException;

/**
 * M-15-T01 receive (P-53 on the order and the location): the goods that actually arrived, per line, within the
 * accepted quantity still expected, at a location that may serve the order. Each line becomes a part; nothing is
 * available before inspection. A later receipt is M-15-T07 (another part). With `inspection` the same step records
 * M-15-T02 for these parts too — both transitions are kept and stock moves once. Once per idempotency key.
 */
final class T01ReceiveReturn
{
    public function __construct(
        private readonly ReturnWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly ReturnStock $stock,
        private readonly ReturnInspection $inspection,
    ) {}

    /**
     * @param  list<array{order_line_id: int, quantity_milli: int, good_milli?: int|null, damaged_milli?: int|null, rejected_milli?: int|null}>  $lines
     */
    public function handle(User $actor, OrderReturn $return, int $locationId, array $lines, bool $inspect, ?string $note, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): OrderReturn
    {
        $payload = ['return' => $return->id, 'location' => $locationId, 'lines' => $lines, 'inspect' => $inspect, 'note' => $note, 'lock_version' => $lockVersion];

        $this->write->run('receive', $actor, $idempotencyKey, $payload, function () use ($actor, $return, $locationId, $lines, $inspect, $note, $lockVersion, $source) {
            $locked = ReturnLock::lock($return, $lockVersion, 'T07');
            Access::authorize($actor, Permission::P53, $locked);
            $location = OrderStock::usableFor($locked->order, $locationId);
            Access::authorize($actor, Permission::P53, $location);
            $from = $locked->status;
            $transition = OrderReturnPart::query()->where('order_return_id', $locked->id)->exists() ? 'T07' : 'T01';

            $own = OrderReturnLine::query()->with('orderLine')->where('order_return_id', $locked->id)->lockForUpdate()->get()->keyBy('order_line_id');
            $outstanding = $own->map(fn (OrderReturnLine $l) => ['outstanding' => $l->outstandingMilli()])->all();
            if (array_sum(array_column($outstanding, 'outstanding')) === 0) {
                throw new InvalidTransition(StateMachine::M15, $transition, 'nothing_expected');
            }
            $picked = T00RequestReturn::pick(array_map(fn (array $l) => ['order_line_id' => $l['order_line_id'], 'quantity_milli' => $l['quantity_milli']], $lines), $outstanding, 'outstanding');

            $parts = [];
            foreach ($lines as $i => $input) {
                $lineId = (int) $input['order_line_id'];
                if (! isset($picked[$lineId])) {
                    continue;
                }
                /** @var OrderReturnLine $line */
                $line = $own[$lineId];
                $line->forceFill(['received_milli' => $line->received_milli + $picked[$lineId]])->save();
                $part = OrderReturnPart::query()->create([
                    'order_return_id' => $locked->id, 'order_return_line_id' => $line->id, 'location_id' => $location->id,
                    'quantity_milli' => $picked[$lineId], 'received_by' => $actor->id, 'received_at' => now(),
                ]);
                $part->setRelation('line', $line);
                $part->setRelation('location', $location);
                $parts[$i] = $part;
            }

            $after = ReturnProgress::derive($locked);
            ReturnLock::bump($locked, $after === ReturnStatus::Inspected ? ReturnStatus::Received : $after);
            $this->transitions->record(StateMachine::M15, $transition, $locked, $from, ReturnStatus::Received, $actor, $source, null, [
                'number' => $locked->number, 'location' => $location->code,
                'parts' => array_map(fn (OrderReturnPart $p) => ['part' => $p->id, 'order_line_id' => $p->line->order_line_id, 'quantity_milli' => $p->quantity_milli], array_values($parts)),
            ]);
            Audit::record(AuditAction::ReturnReceived, $locked, $actor, after: ['number' => $locked->number, 'location' => $location->code, 'parts' => count($parts)], source: $source, branchIds: [$locked->order->branch_id], label: $locked->number);

            if ($inspect) {
                $this->stock->lockFor($parts);
                foreach ($parts as $i => $part) {
                    $input = $lines[$i];
                    if (! isset($input['good_milli']) && ! isset($input['damaged_milli']) && ! isset($input['rejected_milli'])) {
                        throw ValidationException::withMessages(['lines.'.$i.'.good' => __('returns.error.inspection_sum', ['received' => ''])]);
                    }
                    $this->inspection->apply($actor, $locked, $part, [
                        'good_milli' => (int) ($input['good_milli'] ?? 0), 'damaged_milli' => (int) ($input['damaged_milli'] ?? 0), 'rejected_milli' => (int) ($input['rejected_milli'] ?? 0),
                    ], $note, 'lines.'.$i.'.good', 'T02');
                }
                T02InspectReturn::finish($this->transitions, $locked, ReturnStatus::Received, $parts, $actor, $source, $note);
            }

            return ['return_id' => $locked->id];
        });

        return $return->refresh();
    }
}
