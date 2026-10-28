<?php

namespace App\Actions\StateMachines\M27ActionResult;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ActionStatus;
use App\Enums\StateMachine;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\User;
use App\Workflow\StageEngine;
use App\Workflow\Transition;

/**
 * M-27-T07 (P-17 through the order's need fields, or P-28): an approved need change makes a «غير مطلوب» action apply
 * again — unavailable, or ready when its stage is active and its prerequisites are met. Called inside the order update
 * transaction (row already locked). A need that no longer applies never silently drops work: that is T08 (P-28).
 */
final class T07ApplyNeedChange
{
    public function __construct(private readonly StageEngine $engine) {}

    public function handle(User $actor, Order $order, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        if ($order->workflow()->doesntExist()) {
            return;
        }

        $changed = [];
        foreach (OrderAction::query()->where('order_id', $order->id)->where('status', ActionStatus::NotRequired->value)->get() as $action) {
            if (! $action->appliesTo($order)) {
                continue;
            }
            Transition::check(StateMachine::M27, ActionStatus::transitions(), $action->status, 'T07');
            $this->engine->setStatus($action, ActionStatus::NotRequired, ActionStatus::Unavailable, 'T07', $actor, context: ['condition' => $action->applies_when], source: $source);
            $changed[] = $action->key;
        }

        if ($changed !== []) {
            Audit::record(AuditAction::ActionNeedChanged, $order, $actor, after: ['applies' => $changed], source: $source, label: $order->number);
            $this->engine->refresh($order, $actor);
        }
    }
}
