<?php

namespace App\Actions\StateMachines\M26Decision;

use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DiscountDecisionStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\DiscountDecision;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\Transition;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * M-26-T03: the requester withdraws a pending request (the need went away). A cancelled decision is never reused.
 */
final class T03CancelDiscount
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, DiscountDecision $decision, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): DiscountDecision
    {
        return LockingTransaction::run(function () use ($actor, $decision, $lockVersion, $source) {
            [$order, $decision] = AgreementLock::decision($decision, $lockVersion, 'T03');
            if ($decision->requested_by !== $actor->id) {
                throw new AuthorizationException;
            }
            Transition::check(StateMachine::M26, DiscountDecisionStatus::transitions(), $decision->status, 'T03');

            $decision->forceFill(['status' => DiscountDecisionStatus::Cancelled, 'lock_version' => $decision->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M26, 'T03', $decision, DiscountDecisionStatus::Requested, DiscountDecisionStatus::Cancelled, $actor, $source, context: [
                'order_id' => $order->id,
            ]);
            Audit::record(AuditAction::DiscountCancelled, $order, $actor, after: ['subject' => $decision->subject_type.':'.$decision->subject_id],
                source: $source, label: $order->number);

            return $decision;
        });
    }
}
