<?php

namespace App\Actions\StateMachines\M26Decision;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\ContentHash;
use App\Agreements\DiscountCheck;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ChangeStatus;
use App\Enums\DiscountDecisionStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DiscountDecision;
use App\Models\User;
use App\Workflow\Transition;
use App\Workflow\WorkGuard;

/**
 * M-26 creation (AGR-02): the preparer asks for a discount above their own limit on the exact priced content of a
 * quote draft (P-29) or an agreement change (P-36). The request carries the reason, the base and the discount; it
 * changes nothing until an authorized supervisor decides, and the quote cannot be sent before that.
 */
final class T00RequestDiscount
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, AgreementDocument|AgreementChange $subject, string $reason, ?int $lockVersion, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): DiscountDecision
    {
        $reason = WorkGuard::reason($reason);
        $type = $subject instanceof AgreementDocument ? DiscountDecision::SUBJECT_DOCUMENT : DiscountDecision::SUBJECT_CHANGE;
        $payload = ['subject' => $type.':'.$subject->id, 'reason' => $reason, 'lock_version' => $lockVersion];

        $result = $this->write->run('discount.request', $actor, $idempotencyKey, $payload, function () use ($actor, $subject, $type, $reason, $lockVersion, $source) {
            if ($subject instanceof AgreementDocument) {
                [$order, $subject] = AgreementLock::document($subject, $lockVersion, 'T00');
                Access::authorize($actor, Permission::P29, $order);
                if ($subject->kind !== DocumentKind::Quote || $subject->status !== DocumentStatus::Draft) {
                    Transition::refuse(StateMachine::M26, 'T00', 'not_draft');
                }
            } else {
                [$order, $subject] = AgreementLock::change($subject, $lockVersion, 'T00');
                Access::authorize($actor, Permission::P36, $order);
                if (! in_array($subject->status, [ChangeStatus::Draft, ChangeStatus::NeedsRevision], true)) {
                    Transition::refuse(StateMachine::M26, 'T00', 'not_draft');
                }
            }

            $check = DiscountCheck::state($actor, $type, $subject->id, $subject->content);
            if ($check['state'] !== DiscountCheck::NEEDS_DECISION) {
                Transition::refuse(StateMachine::M26, 'T00', $check['state'] === DiscountCheck::REQUESTED ? 'already_requested' : 'within_limit');
            }

            $totals = $subject->content['totals'];
            $decision = DiscountDecision::query()->create([
                'order_id' => $order->id,
                'subject_type' => $type,
                'subject_id' => $subject->id,
                'content_hash' => ContentHash::of(DiscountCheck::priceKey($subject->content)),
                'status' => DiscountDecisionStatus::Requested,
                'requested_by' => $actor->id,
                'base_minor' => (int) $totals['base_minor'],
                'discount_minor' => (int) $totals['discount_minor'],
                'limit_bp' => (int) $check['limit_bp'],
                'reason' => $reason,
            ]);
            $this->transitions->record(StateMachine::M26, 'T00', $decision, null, DiscountDecisionStatus::Requested, $actor, $source, $reason, [
                'order_id' => $order->id, 'subject' => $type.':'.$subject->id,
            ]);
            Audit::record(AuditAction::DiscountRequested, $order, $actor, after: [
                'subject' => $type.':'.$subject->id, 'base_minor' => $decision->base_minor, 'discount_minor' => $decision->discount_minor, 'limit_bp' => $decision->limit_bp,
            ], reason: $reason, source: $source, label: $order->number);

            return ['decision_id' => $decision->id];
        });

        return DiscountDecision::query()->findOrFail((int) $result['result']['decision_id']);
    }
}
