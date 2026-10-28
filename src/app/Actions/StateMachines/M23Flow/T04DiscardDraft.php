<?php

namespace App\Actions\StateMachines\M23Flow;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\FlowStatus;
use App\Enums\StateMachine;
use App\Models\Flow;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\FlowLoader;
use App\Workflow\Transition;

/**
 * M-23-T04 (P-20): drop the draft. The published version and every order are untouched.
 */
final class T04DiscardDraft
{
    public function __construct(private readonly FlowLoader $flows, private readonly TransitionRecorder $transitions) {}

    public function handle(User $actor, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Flow
    {
        Access::authorize($actor, Permission::P20);

        return LockingTransaction::run(fn () => $this->discard($actor, $this->flows->lock($lockVersion), $source));
    }

    /**
     * Caller holds the flow lock and checked P-20.
     */
    public function discard(User $actor, Flow $flow, string $source): Flow
    {
        Transition::check(StateMachine::M23, FlowStatus::transitions(), $flow->status, 'T04');

        $flow->forceFill([
            'status' => FlowStatus::Published,
            'draft' => null,
            'draft_by' => null,
            'draft_updated_at' => null,
            'lock_version' => $flow->lock_version + 1,
        ])->save();

        $this->transitions->record(StateMachine::M23, 'T04', $flow, FlowStatus::PublishedWithDraft, FlowStatus::Published, $actor, $source);
        Audit::record(AuditAction::FlowDraftDiscarded, $flow, $actor, source: $source);

        return $flow;
    }
}
