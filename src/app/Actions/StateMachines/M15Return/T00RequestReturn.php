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
use App\Inventory\Quantity;
use App\Models\Order;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnLine;
use App\Models\Returns\ReturnSettings;
use App\Models\User;
use App\Returns\ReturnAccess;
use App\Returns\ReturnEligibility;
use App\Returns\ReturnNotices;
use App\Returns\ReturnWrite;
use App\Support\GaplessSequence;
use Illuminate\Validation\ValidationException;

/**
 * M-15 request (P-53 or P-42, FUL-11): quantities of delivered ready lines asked back, with a reason. A request holds
 * no eligibility and moves nothing; it is checked against what is still eligible (delivered − accepted by other
 * returns) under the order lock and checked again at acceptance. Takes the gapless `RT-YYYY-000123` and keeps the
 * facility's window in force at the request (indicator only). Once per idempotency key.
 */
final class T00RequestReturn
{
    public function __construct(
        private readonly ReturnWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly GaplessSequence $numbers,
        private readonly ReturnNotices $notices,
    ) {}

    /**
     * @param  list<array{order_line_id: int, quantity_milli: int}>  $lines
     */
    public function handle(User $actor, Order $order, array $lines, string $reason, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): OrderReturn
    {
        $payload = ['order' => $order->id, 'lines' => $lines, 'reason' => $reason];

        $result = $this->write->run('request', $actor, $idempotencyKey, $payload, function () use ($actor, $order, $lines, $reason, $source) {
            $locked = OrderStock::lockOrder($order->id);
            if (! ReturnAccess::canRequest($actor, $locked)) {
                Access::authorize($actor, Permission::P53, $locked);
            }
            if (! $locked->isReady()) {
                throw new InvalidTransition(StateMachine::M15, 'T00', 'not_ready_order');
            }
            $reason = self::reason($reason, 'reason');
            $picked = self::pick($lines, ReturnEligibility::lines($locked), 'eligible');

            $return = OrderReturn::query()->create([
                'number' => $this->numbers->format('order_return', 'RT', 6),
                'order_id' => $locked->id, 'status' => ReturnStatus::Requested, 'reason' => $reason, 'source' => 'staff',
                'window_days' => ReturnSettings::current()->window_days, 'requested_by' => $actor->id,
            ]);
            $return->setRelation('order', $locked);
            foreach ($picked as $lineId => $milli) {
                OrderReturnLine::query()->create([
                    'order_return_id' => $return->id, 'order_line_id' => $lineId, 'requested_milli' => $milli,
                    'unit_value_minor' => $locked->lines()->whereKey($lineId)->value('unit_price_minor'),
                ]);
            }

            $this->transitions->record(StateMachine::M15, 'T00', $return, null, ReturnStatus::Requested, $actor, $source, $reason, ['number' => $return->number, 'order' => $locked->number]);
            Audit::record(AuditAction::ReturnRequested, $return, $actor, after: ['number' => $return->number, 'order' => $locked->number, 'lines' => count($picked)], reason: $reason, source: $source, branchIds: [$locked->branch_id], label: $return->number);
            $this->notices->later('return.requested', $return, Permission::P102, $actor);

            return ['return_id' => $return->id];
        });

        return OrderReturn::query()->findOrFail((int) $result['result']['return_id']);
    }

    /**
     * Validates line quantities against a ceiling per line (`eligible` now, or what was requested) and returns
     * line id → quantity; zero lines are skipped, at least one must remain.
     *
     * @param  list<array{order_line_id: int, quantity_milli: int}>  $input
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, int>
     */
    public static function pick(array $input, array $rows, string $ceiling): array
    {
        $picked = [];
        foreach ($input as $i => $row) {
            $field = 'lines.'.$i;
            $lineId = (int) $row['order_line_id'];
            $milli = (int) $row['quantity_milli'];
            if (! isset($rows[$lineId]) || isset($picked[$lineId])) {
                throw ValidationException::withMessages([$field.'.order_line_id' => __('returns.error.line_unknown')]);
            }
            if ($milli < 0) {
                throw ValidationException::withMessages([$field.'.quantity' => __('returns.error.quantity')]);
            }
            if ($milli === 0) {
                continue;
            }
            $max = (int) $rows[$lineId][$ceiling];
            if ($milli > $max) {
                throw ValidationException::withMessages([$field.'.quantity' => __('returns.error.over_'.$ceiling, ['remaining' => Quantity::format($max)])]);
            }
            $picked[$lineId] = $milli;
        }
        if ($picked === []) {
            throw ValidationException::withMessages(['lines' => __('returns.error.nothing')]);
        }

        return $picked;
    }

    public static function reason(string $text, string $field): string
    {
        $text = trim($text);
        if (mb_strlen($text) < 3) {
            throw ValidationException::withMessages([$field => __('returns.error.reason')]);
        }

        return mb_substr($text, 0, 1000);
    }
}
