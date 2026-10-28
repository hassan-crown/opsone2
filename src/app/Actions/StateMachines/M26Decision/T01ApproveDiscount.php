<?php

namespace App\Actions\StateMachines\M26Decision;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\ContentHash;
use App\Agreements\DiscountCheck;
use App\Agreements\DiscountLimits;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DiscountDecisionStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DiscountDecision;
use App\Models\User;
use App\Money\Money;
use App\Workflow\Transition;
use Illuminate\Validation\ValidationException;

/**
 * M-26-T01 (and T02 through T02RejectDiscount; P-31 over the order, AGR-02): a supervisor in scope approves or rejects a discount request on the
 * content it was asked for. The requester never decides their own request; approving needs an approval limit at
 * least the requested discount (the owner has none). Approval does not send the quote — the preparer sends it.
 * Rejection keeps the quote a draft and needs a reason.
 */
final class T01ApproveDiscount
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, DiscountDecision $decision, bool $approve, ?string $reason, ?int $lockVersion, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): DiscountDecision
    {
        $code = $approve ? 'T01' : 'T02';
        $reason = $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 1000);
        if (! $approve && $reason === null) {
            throw ValidationException::withMessages(['reason' => __('workflow.validation.reason_required')]);
        }
        $payload = ['decision_id' => $decision->id, 'approve' => $approve, 'reason' => $reason, 'lock_version' => $lockVersion];

        $this->write->run('discount.decide', $actor, $idempotencyKey, $payload, function () use ($actor, $decision, $approve, $code, $reason, $lockVersion, $source) {
            [$order, $decision] = AgreementLock::decision($decision, $lockVersion, $code);
            Access::authorize($actor, Permission::P31, $order);
            $to = Transition::check(StateMachine::M26, DiscountDecisionStatus::transitions(), $decision->status, $code);
            if ($decision->requested_by === $actor->id) {
                Transition::refuse(StateMachine::M26, $code, 'own_request');
            }

            $subject = $decision->subject_type === DiscountDecision::SUBJECT_DOCUMENT
                ? AgreementDocument::query()->lockForUpdate()->findOrFail($decision->subject_id)
                : AgreementChange::query()->lockForUpdate()->findOrFail($decision->subject_id);
            if (! hash_equals($decision->content_hash, ContentHash::of(DiscountCheck::priceKey($subject->content)))) {
                Transition::refuse(StateMachine::M26, $code, 'content_changed');
            }
            if ($approve && DiscountLimits::exceeds(Money::of($decision->discount_minor), Money::of($decision->base_minor), DiscountLimits::of($actor, 'approval_bp', $order))) {
                Transition::refuse(StateMachine::M26, $code, 'above_approval_limit');
            }

            $decision->forceFill([
                'status' => $to, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $reason,
                'lock_version' => $decision->lock_version + 1,
            ])->save();
            $this->transitions->record(StateMachine::M26, $code, $decision, DiscountDecisionStatus::Requested, $to, $actor, $source, $reason, [
                'order_id' => $order->id, 'subject' => $decision->subject_type.':'.$decision->subject_id,
            ]);
            Audit::record($approve ? AuditAction::DiscountApproved : AuditAction::DiscountRejected, $order, $actor, after: [
                'subject' => $decision->subject_type.':'.$decision->subject_id, 'discount_minor' => $decision->discount_minor, 'base_minor' => $decision->base_minor,
            ], reason: $reason, source: $source, label: $order->number);

            return ['decision_id' => $decision->id];
        });

        return $decision->refresh();
    }
}
