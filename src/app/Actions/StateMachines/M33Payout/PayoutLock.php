<?php

namespace App\Actions\StateMachines\M33Payout;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Enums\PayoutStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Models\Commissions\CommissionPayout;
use App\Models\Commissions\OrderSale;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Shared steps of an M-33 write: lock the member (one payout decision per member at a time — two payouts can never
 * take the same line), authorize on the payout, lock it, check its version and that the transition leaves from its
 * state. A member never pays themselves.
 */
final class PayoutLock
{
    public static function member(int $userId): User
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Payout writes run inside a transaction.');
        }

        return User::query()->lockForUpdate()->findOrFail($userId);
    }

    public static function authorize(User $actor, CommissionPayout $payout, Permission $permission, string $code): void
    {
        Access::authorize($actor, $permission, $payout);
        if ($actor->id === $payout->user_id) {
            throw new InvalidTransition(StateMachine::M33, $code, 'own_payout');
        }
    }

    public static function payout(User $actor, CommissionPayout $payout, ?int $lockVersion, string $code, Permission $permission = Permission::P96): CommissionPayout
    {
        self::member($payout->user_id);
        $fresh = CommissionPayout::query()->lockForUpdate()->findOrFail($payout->id);
        self::authorize($actor, $fresh, $permission, $code);
        if ($lockVersion !== null && $fresh->lock_version !== $lockVersion) {
            throw new StaleRecord($fresh->lock_version);
        }
        if (! in_array($fresh->status, PayoutStatus::transitions()[$code]['from'], true)) {
            throw new InvalidTransition(StateMachine::M33, $code, 'not_from_'.$fresh->status->value);
        }

        return $fresh;
    }

    /**
     * The orders of the member's sales, locked in id order: no accrual changes under a reservation (P06 «تزامن صرف
     * مع رد»: the balance is checked again under these locks).
     */
    public static function orders(int $userId): void
    {
        $ids = array_values(OrderSale::query()->where('user_id', $userId)->distinct()->pluck('order_id')->map(fn ($id) => (int) $id)->all());
        if ($ids !== []) {
            FinanceLock::orders($ids, StateMachine::M33, 'T01');
        }
    }
}
