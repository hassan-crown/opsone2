<?php

namespace App\Agreements;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ChangeStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\CustomerDecision;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\User;
use App\Workflow\StageEngine;
use App\Workflow\Transition;
use App\Workflow\WorkflowLock;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * M-09-T02/T04 shared by the customer's own decision (CustomerDecisions) and an external approval recorded by staff
 * (T04ApplyChange), plus holding and releasing the affected work (AGR-07). Applying writes the next agreement version
 * once — the earlier version and its approval stay as they were — and releases the hold.
 */
final class ChangeApplication
{
    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly AgreementActivation $activation,
        private readonly DocumentApproval $documents,
        private readonly StageEngine $engine,
    ) {}

    public static function ensureDecidable(AgreementChange $change, string $contentHash, string $code): void
    {
        Transition::check(StateMachine::M09, ChangeStatus::transitions(), $change->status, $code);
        if (! hash_equals((string) $change->content_hash, $contentHash)) {
            Transition::refuse(StateMachine::M09, $code, 'content_changed');
        }
        // A concurrent change was applied first: approving content built on an older agreement is refused.
        $current = OrderAgreement::current($change->order);
        if ($current === null || $current->id !== $change->base_agreement_id) {
            Transition::refuse(StateMachine::M09, $code, 'agreement_changed');
        }
    }

    /**
     * @param  array{method: string, channel?: string|null, decided_at: CarbonInterface, recorded_by?: int|null, customer_mobile?: string|null, note?: string|null}  $how
     */
    public function apply(Order $order, AgreementChange $change, array $how, ?User $actor, string $source): Agreement
    {
        try {
            $decision = CustomerDecision::query()->create([
                'subject_type' => 'change', 'subject_id' => $change->id, 'order_id' => $order->id,
                'content_hash' => (string) $change->content_hash, 'decision' => CustomerDecision::APPROVED,
                'method' => $how['method'], 'channel' => $how['channel'] ?? null, 'decided_at' => $how['decided_at'],
                'recorded_by' => $how['recorded_by'] ?? null, 'customer_id' => $order->customer_id,
                'customer_mobile' => $how['customer_mobile'] ?? null, 'note' => $how['note'] ?? null,
                'approval_key' => 'change:'.$change->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidTransition(StateMachine::M09, 'T04', 'already_applied');
        }

        $base = Agreement::query()->findOrFail($change->base_agreement_id);
        $agreement = $this->activation->activate($order, $change->content, 'change', $change->id, $decision, $actor, $source);

        // The approved quote behind the earlier version is kept as proof, marked replaced (M-08-T07).
        if ($base->source_type === 'document') {
            $quote = AgreementDocument::query()->lockForUpdate()->find($base->source_id);
            if ($quote !== null && $quote->kind === DocumentKind::Quote && $quote->status === DocumentStatus::Approved) {
                $this->documents->replace($order, $quote, 'T07', $actor, $source, ['replaced_by_change' => $change->id]);
            }
        }

        $change->forceFill(['status' => ChangeStatus::Applied, 'open_key' => null, 'applied_agreement_id' => $agreement->id, 'lock_version' => $change->lock_version + 1])->save();
        $this->transitions->record(StateMachine::M09, 'T04', $change, ChangeStatus::AwaitingApproval, ChangeStatus::Applied, $actor, $source, context: [
            'order_id' => $order->id, 'decision_id' => $decision->id, 'agreement_version' => $agreement->version,
            'difference_minor' => $agreement->difference_minor, 'settlement_needed_minor' => $agreement->settlement_needed_minor,
        ]);
        Audit::record(AuditAction::ChangeApplied, $order, $actor, after: [
            'change' => $change->number, 'version' => $agreement->version, 'difference_minor' => $agreement->difference_minor,
            'method' => $how['method'], 'channel' => $how['channel'] ?? null,
        ], source: $source, label: $order->number);
        $this->release($order, $change, $actor);

        return $agreement;
    }

    /**
     * M-09-T02: the customer or staff ask for a correction; the earlier agreement stays in force and the hold stays.
     */
    public function requestRevision(Order $order, AgreementChange $change, string $reason, ?User $actor, string $source): void
    {
        $change->forceFill(['status' => ChangeStatus::NeedsRevision, 'revision_reason' => $reason, 'lock_version' => $change->lock_version + 1])->save();
        $this->transitions->record(StateMachine::M09, 'T02', $change, ChangeStatus::AwaitingApproval, ChangeStatus::NeedsRevision, $actor, $source, $reason, [
            'order_id' => $order->id,
        ]);
        Audit::record(AuditAction::ChangeRevisionRequested, $order, $actor, after: ['change' => $change->number], reason: $reason, source: $source, label: $order->number);
    }

    /**
     * Holds the affected work that is not done yet: its tasks cannot start or complete until the change is decided.
     */
    public function hold(Order $order, AgreementChange $change): void
    {
        $keys = $change->affected ?? [];
        if ($keys === []) {
            return;
        }
        $held = OrderAction::query()->where('order_id', $order->id)->whereIn('key', $keys)->get()
            ->reject(fn (OrderAction $a) => $a->status->satisfies())->pluck('id');
        OrderAction::query()->whereIn('id', $held)->update(['held_by_change_id' => $change->id]);
        WorkflowLock::touch($order);
    }

    public function release(Order $order, AgreementChange $change, ?User $actor): void
    {
        $released = OrderAction::query()->where('order_id', $order->id)->where('held_by_change_id', $change->id)->update(['held_by_change_id' => null]);
        if ($released > 0) {
            WorkflowLock::touch($order);
            $this->engine->refresh($order, $actor);
        }
    }
}
