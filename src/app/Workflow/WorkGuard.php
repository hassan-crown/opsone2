<?php

namespace App\Workflow;

use App\Enums\CustomOrderStatus;
use App\Enums\StateMachine;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

/**
 * Shared preconditions of the P5 transition Actions.
 */
final class WorkGuard
{
    /**
     * Work moves only while the order runs: a paused order keeps its stage and results (M-04-T01) and a cancelled or
     * closed one starts nothing new.
     */
    public static function running(Order $order, StateMachine $machine, string $code): void
    {
        if ($order->status === CustomOrderStatus::Paused->value) {
            Transition::refuse($machine, $code, 'order_paused');
        }
        if ($order->status !== CustomOrderStatus::InProgress->value) {
            Transition::refuse($machine, $code, 'order_not_running');
        }
    }

    public static function reason(?string $reason, string $field = 'reason'): string
    {
        $reason = trim((string) $reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([$field => __('workflow.validation.reason_required')]);
        }

        return $reason;
    }
}
