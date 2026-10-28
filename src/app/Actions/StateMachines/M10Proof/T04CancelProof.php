<?php

namespace App\Actions\StateMachines\M10Proof;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ProofStatus;
use App\Enums\StateMachine;
use App\Models\Finance\PaymentProof;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Validation\ValidationException;

/**
 * M-10-T04 by staff (P-40): close a proof that was never confirmed (a duplicate upload, a wrong order). Only before
 * any confirmed money; a confirmed collection is corrected from its own record (6c). The file stays.
 */
final class T04CancelProof
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, PaymentProof $proof, string $reason, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): PaymentProof
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('finance.validation.reason')]);
        }

        return LockingTransaction::run(function () use ($actor, $proof, $reason, $lockVersion, $source) {
            [$order, $proof] = ProofDecision::lock($actor, $proof, $lockVersion, 'T04');
            $from = $proof->status;
            $proof->forceFill(['status' => ProofStatus::Cancelled, 'decided_by' => $actor->id, 'decided_at' => now(), 'lock_version' => $proof->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M10, 'T04', $proof, $from, ProofStatus::Cancelled, $actor, $source, $reason);
            Audit::record(AuditAction::ProofCancelled, $order, $actor, after: ['proof' => $proof->id], reason: $reason, source: $source, label: $order->number);

            return $proof;
        });
    }
}
