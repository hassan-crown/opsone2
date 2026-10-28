<?php

namespace App\Actions\StateMachines\M34Cancellation;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Cancellation\CancellationApply;
use App\Cancellation\CancellationInput;
use App\Cancellation\CancellationWrite;
use App\Enums\CancellationStatus;
use App\Inventory\OrderStock;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Order;
use App\Models\User;
use App\Support\GaplessSequence;

/**
 * M-34-T03 (P-18): the customer asked outside the system (call, message, visit); management records the request with
 * its source, date and reason and accepts it in one action — no second employee, no customer login (SRS-COM-14). When
 * a request is already pending on the order, that request is the one decided (no second request).
 */
final class T03RecordAndAcceptCancellation
{
    public function __construct(
        private readonly CancellationWrite $write,
        private readonly CancellationApply $apply,
        private readonly GaplessSequence $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data  source, channel, requested_on, reason, scope, lines?, retained?
     */
    public function handle(User $actor, Order $order, array $data, string $hash, ?int $lockVersion, ?string $key, string $source = TransitionRecorder::SOURCE_WEB): OrderCancellation
    {
        $request = CancellationWrite::request($data);
        $input = CancellationInput::fromInput($data);

        $result = $this->write->run('record', $actor, $key, ['order_id' => $order->id, 'hash' => $hash, 'lock_version' => $lockVersion, ...$request, ...$input->toArray()],
            function () use ($actor, $order, $request, $input, $hash, $lockVersion, $source) {
                $order = OrderStock::lockOrder($order->id, $lockVersion);
                Access::authorize($actor, Permission::P18, $order);
                CancellationWrite::authorizeAmounts($actor, $order, $input);

                $pending = OrderCancellation::query()->where('active_key', $order->id)->lockForUpdate()->first();
                if ($pending !== null && $pending->status === CancellationStatus::Pending) {
                    $pending->forceFill([...$request, 'scope' => $input->scope, 'lines' => $input->lines === [] ? null : $input->lines, 'retained_minor' => $input->retainedMinor]);
                    $pending->setRelation('order', $order);
                    $this->apply->apply($actor, $order, $pending, $hash, 'T02', $source);

                    return ['cancellation_id' => $pending->id];
                }

                $cancellation = T01RequestCancellation::create($this->numbers, $actor, $order, $request, $input);
                $cancellation->setRelation('order', $order);
                $this->apply->apply($actor, $order, $cancellation, $hash, 'T03', $source);

                return ['cancellation_id' => $cancellation->id];
            });

        return OrderCancellation::query()->findOrFail((int) $result['result']['cancellation_id']);
    }
}
