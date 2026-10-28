<?php

namespace App\Actions\StateMachines\M27ActionResult;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ActionStatus;
use App\Enums\StateMachine;
use App\Models\OrderAction;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\ActionLoader;
use App\Workflow\StageEngine;
use App\Workflow\Transition;
use App\Workflow\WorkflowLock;
use App\Workflow\WorkGuard;

/**
 * M-27-T04 (P-35, owner/manager by default — Q-30 a): allow the stage to proceed despite this requirement, with a
 * reason. Only the condition advances: an unpaid amount stays owed, a missing approval stays missing, and the open
 * work stays under follow-up (WF-09, WF-12). Financial limits on allowances arrive with P6.
 */
final class T04AllowProceed
{
    public function __construct(private readonly StageEngine $engine) {}

    public function handle(User $actor, OrderAction $action, string $reason, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): OrderAction
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $action, $reason, $lockVersion, $source) {
            [$order, $action] = ActionLoader::lock($action, $lockVersion);
            Access::authorize($actor, Permission::P35, $action);
            $from = $action->status;
            Transition::check(StateMachine::M27, ActionStatus::transitions(), $from, 'T04');
            if (! $action->isRequired()) {
                Transition::refuse(StateMachine::M27, 'T04', 'optional');
            }

            $action->forceFill(['allowed_by' => $actor->id, 'allowed_reason' => $reason])->save();
            $this->engine->setStatus($action, $from, ActionStatus::Allowed, 'T04', $actor, $reason, source: $source);
            Audit::record(AuditAction::ActionAllowed, $order, $actor, before: ['action' => $action->key, 'status' => $from->value],
                after: ['action' => $action->key, 'status' => ActionStatus::Allowed->value], reason: $reason, source: $source, label: $order->number);
            WorkflowLock::touch($order);
            $this->engine->releaseHeldBy($action);
            $this->engine->refresh($order, $actor);

            return $action;
        });
    }
}
