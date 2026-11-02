<?php

namespace App\EInvoicing;

use App\Access\Access;
use App\Access\Permission;
use App\Models\EInvoicing\EInvoice;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;

/**
 * Who may do what with e-invoicing (ADR-0033). The tax profile, onboarding, retries and manual notes are the facility
 * owner's (owner membership AND P-02 through the Gate, so a support identity or a disabled owner never does). A
 * document's PDF/XML is also open to whoever sees the order's money (P-37 on that order).
 */
final class ZatcaAccess
{
    public static function manages(?User $user): bool
    {
        if ($user === null || $user->is_support) {
            return false;
        }
        $membership = Membership::query()->where('user_id', $user->id)->first();

        return $membership !== null && $membership->isOwner() && Access::can($user, Permission::P02);
    }

    public static function views(?User $user, EInvoice $invoice): bool
    {
        if (self::manages($user)) {
            return true;
        }
        $order = Order::query()->find($invoice->order_id);

        return $user !== null && $order !== null && Access::can($user, Permission::P37, $order);
    }
}
