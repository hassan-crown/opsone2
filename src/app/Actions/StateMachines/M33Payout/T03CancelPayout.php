<?php

namespace App\Actions\StateMachines\M33Payout;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PayoutStatus;
use App\Enums\StateMachine;
use App\Models\Commissions\CommissionPayout;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Validation\ValidationException;

/**
 * M-33-T03 (P-96): withdraw a draft or reserved payout with a reason. Nothing was paid: the held lines are released
 * (a cancelled payout no longer takes from the balance) and the reason is kept.
 */
final class T03CancelPayout
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, CommissionPayout $payout, string $reason, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): CommissionPayout
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('commissions.validation.reason')]);
        }

        return LockingTransaction::run(function () use ($actor, $payout, $reason, $lockVersion, $source) {
            $fresh = PayoutLock::payout($actor, $payout, $lockVersion, 'T03');
            $from = $fresh->status;
            $fresh->forceFill([
                'status' => PayoutStatus::Cancelled, 'reason' => $reason, 'closed_by' => $actor->id, 'closed_at' => now(),
                'lock_version' => $fresh->lock_version + 1,
            ])->save();
            $this->transitions->record(StateMachine::M33, 'T03', $fresh, $from, PayoutStatus::Cancelled, $actor, $source, $reason);
            Audit::record(AuditAction::CommissionPayoutCancelled, $fresh, $actor, before: ['status' => $from->value], reason: $reason, source: $source, label: $fresh->member->name);

            return $fresh;
        });
    }
}
