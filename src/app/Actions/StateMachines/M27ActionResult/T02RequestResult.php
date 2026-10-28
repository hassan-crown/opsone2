<?php

namespace App\Actions\StateMachines\M27ActionResult;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
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
 * M-27-T02 (authorized executor, P-24 over the action): the action now waits for a result from the customer or another
 * part (document approval, collection, receipt); the stage shows what it waits for (M-06-T02).
 */
final class T02RequestResult
{
    public function __construct(private readonly StageEngine $engine) {}

    public function handle(User $actor, OrderAction $action, string $awaiting, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): OrderAction
    {
        $awaiting = WorkGuard::reason($awaiting, 'awaiting');

        return LockingTransaction::run(function () use ($actor, $action, $awaiting, $lockVersion, $source) {
            [$order, $action] = ActionLoader::lock($action, $lockVersion);
            Access::authorize($actor, Permission::P24, $action);
            Transition::check(StateMachine::M27, ActionStatus::transitions(), $action->status, 'T02');
            WorkGuard::running($order, StateMachine::M27, 'T02');

            $this->engine->setStatus($action, ActionStatus::Ready, ActionStatus::AwaitingResult, 'T02', $actor, $awaiting, source: $source);
            WorkflowLock::touch($order);
            $this->engine->refresh($order, $actor);

            return $action;
        });
    }
}
