<?php

namespace App\Actions\StateMachines\M10Proof;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Enums\ProofStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Models\Finance\PaymentProof;
use App\Models\Order;
use App\Models\User;
use App\Support\StaleRecord;

/**
 * Shared steps of a staff decision on a proof (P-40): lock the order, authorize, lock the proof, check its version
 * and that the transition leaves from its state.
 */
final class ProofDecision
{
    /**
     * @return array{0: Order, 1: PaymentProof}
     */
    public static function lock(User $actor, PaymentProof $proof, ?int $lockVersion, string $code): array
    {
        $order = FinanceLock::order($proof->order_id, StateMachine::M10, $code);
        Access::authorize($actor, Permission::P40, $order);
        $fresh = PaymentProof::query()->lockForUpdate()->findOrFail($proof->id);
        if ($lockVersion !== null && $fresh->lock_version !== $lockVersion) {
            throw new StaleRecord($fresh->lock_version);
        }
        $allowed = ProofStatus::transitions()[$code]['from'];
        $replaced = PaymentProof::query()->where('replaces_id', $fresh->id)->exists();
        if (! in_array($fresh->status, $allowed, true) || $replaced || $fresh->payment_id !== null) {
            throw new InvalidTransition(StateMachine::M10, $code, 'not_from_'.$fresh->status->value);
        }
        $fresh->setRelation('order', $order);

        return [$order, $fresh];
    }
}
