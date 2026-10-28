<?php

namespace App\Actions\StateMachines\M09Change;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\Numbers;
use App\Agreements\OrderAgreement;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ChangeStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementChange;
use App\Models\Order;
use App\Models\User;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;

/**
 * M-09 creation (P-36, AGR-07): a change request on the active agreement, starting from its content so nothing is
 * typed again. One open change per order; the agreement in force does not move until the change is applied.
 */
final class T00CreateChange
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly Numbers $numbers,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, Order $order, string $description, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): AgreementChange
    {
        $description = WorkGuard::reason($description, 'description');

        $result = $this->write->run('change.create', $actor, $idempotencyKey, ['order_id' => $order->id, 'description' => $description], function () use ($actor, $order, $description, $source) {
            $order = AgreementLock::order($order->id, StateMachine::M09, 'T00');
            Access::authorize($actor, Permission::P36, $order);
            $agreement = OrderAgreement::current($order) ?? Transition::refuse(StateMachine::M09, 'T00', 'no_agreement');
            if (OrderAgreement::pendingChange($order) !== null) {
                Transition::refuse(StateMachine::M09, 'T00', 'change_open');
            }

            $change = AgreementChange::query()->create([
                'order_id' => $order->id, 'number' => $this->numbers->change(), 'base_agreement_id' => $agreement->id,
                'status' => ChangeStatus::Draft, 'open_key' => $order->id, 'description' => $description,
                'content' => $agreement->content, 'affected' => [], 'delay_days' => 0, 'created_by' => $actor->id,
            ]);
            $this->transitions->record(StateMachine::M09, 'T00', $change, null, ChangeStatus::Draft, $actor, $source, context: [
                'order_id' => $order->id, 'base_version' => $agreement->version,
            ]);
            Audit::record(AuditAction::ChangeCreated, $order, $actor, after: ['change' => $change->number, 'base_version' => $agreement->version],
                source: $source, label: $order->number);

            return ['change_id' => $change->id];
        });

        return AgreementChange::query()->findOrFail((int) $result['result']['change_id']);
    }
}
