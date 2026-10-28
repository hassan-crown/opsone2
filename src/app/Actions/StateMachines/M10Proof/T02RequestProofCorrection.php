<?php

namespace App\Actions\StateMachines\M10Proof;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ProofStatus;
use App\Enums\StateMachine;
use App\Finance\CollectionNotices;
use App\Models\Finance\PaymentProof;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Validation\ValidationException;

/**
 * M-10-T02 (P-40, FinReceiptFix): the proof goes back to the customer with a reason they can read. Nothing is
 * collected and the file is kept; the customer uploads a replacement (T03) that is reviewed again.
 */
final class T02RequestProofCorrection
{
    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly CollectionNotices $notices,
    ) {}

    public function handle(User $actor, PaymentProof $proof, string $reason, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): PaymentProof
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('finance.validation.reason')]);
        }

        return LockingTransaction::run(function () use ($actor, $proof, $reason, $lockVersion, $source) {
            [$order, $proof] = ProofDecision::lock($actor, $proof, $lockVersion, 'T02');
            $proof->forceFill([
                'status' => ProofStatus::NeedsCorrection, 'correction_reason' => $reason, 'decided_by' => $actor->id, 'decided_at' => now(),
                'lock_version' => $proof->lock_version + 1,
            ])->save();
            $this->transitions->record(StateMachine::M10, 'T02', $proof, ProofStatus::UnderReview, ProofStatus::NeedsCorrection, $actor, $source, $reason);
            Audit::record(AuditAction::ProofCorrectionRequested, $order, $actor, after: ['proof' => $proof->id], reason: $reason, source: $source, label: $order->number);
            $this->notices->proofNeedsCorrection($proof, $order, $reason);

            return $proof;
        });
    }
}
