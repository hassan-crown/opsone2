<?php

namespace App\Actions\StateMachines\M34Cancellation;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Cancellation\CancellationApply;
use App\Cancellation\CancellationInput;
use App\Cancellation\CancellationWrite;
use App\Enums\CancellationStatus;
use App\Enums\StateMachine;
use App\Inventory\OrderStock;
use App\Models\Cancellation\OrderCancellation;
use App\Models\User;
use App\Support\StaleRecord;

/**
 * M-34-T02 (P-18): accept a pending request after reviewing its impact, with M-04-T05 / M-05-T09. The reviewer decides
 * the scope (full or the lines and quantities to cancel, SRS-COM-14) and confirms the reviewed fingerprint; everything
 * applies at once or nothing (CancellationApply). Collected money or a pending refund never blocks the acceptance
 * (FIN-14): it becomes the settlement follow-up. The first correct concurrent decision wins (M-34-T06).
 */
final class T02AcceptCancellation
{
    public function __construct(
        private readonly CancellationWrite $write,
        private readonly CancellationApply $apply,
    ) {}

    /**
     * @param  array<string, mixed>  $data  scope, lines?, retained?
     */
    public function handle(User $actor, OrderCancellation $cancellation, array $data, string $hash, ?int $lockVersion, ?string $key, string $source = TransitionRecorder::SOURCE_WEB): OrderCancellation
    {
        $input = CancellationInput::fromInput($data);

        $result = $this->write->run('accept', $actor, $key, ['cancellation_id' => $cancellation->id, 'hash' => $hash, 'lock_version' => $lockVersion, ...$input->toArray()],
            function () use ($actor, $cancellation, $input, $hash, $lockVersion, $source) {
                $order = OrderStock::lockOrder($cancellation->order_id, $lockVersion);
                $locked = OrderCancellation::query()->lockForUpdate()->findOrFail($cancellation->id);
                $locked->setRelation('order', $order);
                Access::authorize($actor, Permission::P18, $order);
                CancellationWrite::authorizeAmounts($actor, $order, $input);
                if ($locked->status !== CancellationStatus::Pending) {
                    throw new InvalidTransition(StateMachine::M34, 'T02', 'not_from_'.$locked->status->value);
                }
                $locked->forceFill(['scope' => $input->scope, 'lines' => $input->lines === [] ? null : $input->lines, 'retained_minor' => $input->retainedMinor]);
                $this->apply->apply($actor, $order, $locked, $hash, 'T02', $source);

                return ['cancellation_id' => $locked->id];
            });

        return OrderCancellation::query()->findOrFail((int) $result['result']['cancellation_id']);
    }

    /**
     * The version a screen must send back for a pending request (the order's — the request itself only changes when
     * decided).
     */
    public static function checkVersion(OrderCancellation $cancellation, ?int $expected): void
    {
        if ($expected !== null && $cancellation->lock_version !== $expected) {
            throw new StaleRecord($cancellation->lock_version);
        }
    }
}
