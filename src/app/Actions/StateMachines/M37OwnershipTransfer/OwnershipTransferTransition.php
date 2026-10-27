<?php

namespace App\Actions\StateMachines\M37OwnershipTransfer;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Enums\OwnershipTransferStatus;
use App\Enums\StateMachine;
use App\Models\Membership;
use App\Models\OwnershipTransfer;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Shared guard for the decisions on a requested transfer (T01..T04): lock, require «requested», run, log.
 */
abstract class OwnershipTransferTransition
{
    public function __construct(protected readonly TransitionRecorder $transitions) {}

    /**
     * @param  (Closure(OwnershipTransfer): ?string)|null  $guard  returns the failed condition, or null
     * @param  (Closure(OwnershipTransfer): void)|null  $effect
     */
    protected function decide(
        string $code,
        OwnershipTransfer $transfer,
        OwnershipTransferStatus $to,
        ?User $actor,
        ?Closure $guard,
        ?Closure $effect,
        string $source,
        ?string $reason = null,
    ): OwnershipTransfer {
        return DB::transaction(function () use ($code, $transfer, $to, $actor, $guard, $effect, $source, $reason) {
            $locked = OwnershipTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($locked->status !== OwnershipTransferStatus::Requested) {
                throw new InvalidTransition(StateMachine::M37, $code, 'not_requested');
            }
            if ($guard !== null && ($failed = $guard($locked)) !== null) {
                throw new InvalidTransition(StateMachine::M37, $code, $failed);
            }

            if ($effect !== null) {
                $effect($locked);
            }

            $locked->forceFill(['status' => $to, 'decided_at' => now()])->save();
            $this->transitions->record(StateMachine::M37, $code, $locked, OwnershipTransferStatus::Requested, $to, $actor, $source, $reason);

            return $locked;
        });
    }

    protected function isMember(User $user, int $membershipId): bool
    {
        return Membership::query()->whereKey($membershipId)->where('user_id', $user->id)->exists();
    }
}
