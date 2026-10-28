<?php

namespace App\Actions\StateMachines\M34Cancellation;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Cancellation\CancellationImpact;
use App\Cancellation\CancellationInput;
use App\Cancellation\CancellationWrite;
use App\Enums\CancellationStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\StateMachine;
use App\Inventory\OrderStock;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderTransitions;
use App\Support\GaplessSequence;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * M-34-T01 recorded by management (with M-04-T04, P-18): the customer's request is on the order and waits for a
 * decision. A custom order becomes «cancellation pending», so no new irreversible work starts (SRS-WF-13: tasks,
 * stage completion and deliveries refuse while it is not running); what was done, collected or delivered stays. One
 * active request per order. The customer's own request from their account (P-99) is the portal's (P8) and lands here.
 */
final class T01RequestCancellation
{
    public function __construct(
        private readonly CancellationWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly GaplessSequence $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data  source, channel, requested_on, reason, scope, lines?, retained?
     */
    public function handle(User $actor, Order $order, array $data, ?int $lockVersion, ?string $key, string $source = TransitionRecorder::SOURCE_WEB): OrderCancellation
    {
        $request = CancellationWrite::request($data);
        $input = CancellationInput::fromInput($data);

        $result = $this->write->run('request', $actor, $key, ['order_id' => $order->id, 'lock_version' => $lockVersion, ...$request, ...$input->toArray()],
            function () use ($actor, $order, $request, $input, $lockVersion, $source) {
                $order = OrderStock::lockOrder($order->id, $lockVersion);
                Access::authorize($actor, Permission::P18, $order);
                CancellationWrite::authorizeAmounts($actor, $order, $input);
                // The request is checked against today's order so a request that could never apply is refused now.
                CancellationImpact::compute($order, $input);
                if ($order->status === CustomOrderStatus::CancellationPending->value) {
                    throw new InvalidTransition(StateMachine::M34, 'T01', 'request_pending');
                }

                $cancellation = self::create($this->numbers, $actor, $order, $request, $input);
                $this->transitions->record(StateMachine::M34, 'T01', $cancellation, null, CancellationStatus::Pending, $actor, $source, $request['reason'], [
                    'order_id' => $order->id, 'scope' => $input->scope, 'source' => $request['source'],
                ]);
                if (! $order->isReady()) {
                    OrderTransitions::check($order, 'T04');
                    $from = $order->state();
                    $order->status = CustomOrderStatus::CancellationPending->value;
                    $order->lock_version++;
                    $order->save();
                    $this->transitions->record(StateMachine::M04, 'T04', $order, $from, CustomOrderStatus::CancellationPending, $actor, $source, $request['reason'], [
                        'cancellation' => $cancellation->number,
                    ]);
                }
                Audit::record(AuditAction::CancellationRequested, $order, $actor, after: [
                    'cancellation' => $cancellation->number, 'scope' => $input->scope, 'source' => $request['source'], 'channel' => $request['channel'],
                ], reason: $request['reason'], source: $source, label: $order->number);

                return ['cancellation_id' => $cancellation->id];
            });

        return OrderCancellation::query()->findOrFail((int) $result['result']['cancellation_id']);
    }

    /**
     * A pending request row with its number; one per order (`active_key`).
     *
     * @param  array{source: string, channel: string|null, requested_on: string, reason: string}  $request
     */
    public static function create(GaplessSequence $numbers, User $actor, Order $order, array $request, CancellationInput $input): OrderCancellation
    {
        if (OrderCancellation::query()->where('active_key', $order->id)->exists()) {
            throw new InvalidTransition(StateMachine::M34, 'T01', 'request_pending');
        }
        try {
            return OrderCancellation::query()->create([
                'number' => $numbers->format('cancellation', 'CN', 6), 'order_id' => $order->id, 'scope' => $input->scope,
                'status' => CancellationStatus::Pending, ...$request, 'lines' => $input->lines === [] ? null : $input->lines,
                'retained_minor' => $input->retainedMinor, 'order_status_before' => $order->status, 'requested_by' => $actor->id,
                'active_key' => $order->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidTransition(StateMachine::M34, 'T01', 'request_pending');
        }
    }
}
