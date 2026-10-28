<?php

namespace App\Actions\StateMachines\M09Change;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\ChangeApplication;
use App\Agreements\ContentHash;
use App\Agreements\DiscountCheck;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ChangeStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\DiscountDecision;
use App\Models\User;
use App\Money\Money;
use App\Workflow\Transition;

/**
 * M-09-T01 / T03 (P-36): present the change for approval — from a draft (T01) or again after a revision request (T03,
 * the earlier presentation kept in `presentations`). Fixes the content and its hash, the money difference against the
 * agreement in force and the extra days, and holds the affected work that is not done yet. Content equal to the
 * agreement is not a change; a discount above the presenter's limit needs an approved decision (AGR-02).
 */
final class T01PresentChange
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly ChangeApplication $application,
    ) {}

    public function handle(User $actor, AgreementChange $change, ?int $lockVersion, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): AgreementChange
    {
        $this->write->run('change.present', $actor, $idempotencyKey, ['change_id' => $change->id, 'lock_version' => $lockVersion], function () use ($actor, $change, $lockVersion, $source) {
            [$order, $change] = AgreementLock::change($change, $lockVersion, 'T01');
            $code = $change->status === ChangeStatus::NeedsRevision ? 'T03' : 'T01';
            Access::authorize($actor, Permission::P36, $order);
            $from = $change->status;
            Transition::check(StateMachine::M09, ChangeStatus::transitions(), $from, $code);

            $base = Agreement::query()->findOrFail($change->base_agreement_id);
            $content = $change->content;
            $state = DiscountCheck::state($actor, DiscountDecision::SUBJECT_CHANGE, $change->id, $content, $order)['state'];
            match ($state) {
                DiscountCheck::INCOMPLETE => Transition::refuse(StateMachine::M09, $code, 'content_incomplete'),
                DiscountCheck::NEEDS_DECISION, DiscountCheck::REQUESTED => Transition::refuse(StateMachine::M09, $code, 'discount_needs_decision'),
                default => null,
            };
            if (self::sameAgreement($content, $base->content) && $change->delay_days === 0) {
                Transition::refuse(StateMachine::M09, $code, 'no_change');
            }

            $difference = Money::of((int) $content['totals']['total_minor'])->minus(Money::of($base->total_minor));
            $hash = ContentHash::of(['content' => $content, 'description' => $change->description, 'delay_days' => $change->delay_days, 'base' => $base->id]);
            $presentations = $change->presentations ?? [];
            $presentations[] = ['at' => now()->toIso8601String(), 'by' => $actor->id, 'content_hash' => $hash,
                'total_minor' => $content['totals']['total_minor'], 'difference_minor' => $difference->minor];

            $change->forceFill([
                'status' => ChangeStatus::AwaitingApproval, 'content_hash' => $hash, 'difference_minor' => $difference->minor,
                'presentations' => $presentations, 'presented_at' => now(), 'presented_by' => $actor->id, 'revision_reason' => null,
                'lock_version' => $change->lock_version + 1,
            ])->save();
            $this->application->hold($order, $change);
            $this->transitions->record(StateMachine::M09, $code, $change, $from, ChangeStatus::AwaitingApproval, $actor, $source, context: [
                'order_id' => $order->id, 'content_hash' => $hash, 'difference_minor' => $difference->minor, 'affected' => $change->affected,
            ]);
            Audit::record(AuditAction::ChangePresented, $order, $actor, after: [
                'change' => $change->number, 'difference_minor' => $difference->minor, 'delay_days' => $change->delay_days, 'affected' => $change->affected,
            ], source: $source, label: $order->number);

            return ['change_id' => $change->id];
        });

        return $change->refresh();
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function sameAgreement(array $a, array $b): bool
    {
        $key = fn (array $c) => ContentHash::of([...DiscountCheck::priceKey($c), 'terms' => $c['terms'] ?? null, 'execution_days' => $c['execution_days'] ?? null]);

        return $key($a) === $key($b);
    }
}
