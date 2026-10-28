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
use App\Workflow\FlowCheck;
use App\Workflow\FlowLoader;
use App\Workflow\FlowProvisioner;
use App\Workflow\Transition;

/**
 * M-23-T03 (P-21): publish the draft as a new immutable version after FlowCheck passes (422 with structured issues
 * otherwise). Applies to new orders only; running orders keep their pinned version (WF-07, WF-10).
 */
final class T03PublishFlow
{
    public function __construct(
        private readonly FlowLoader $flows,
        private readonly FlowCheck $check,
        private readonly FlowProvisioner $provisioner,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Flow
    {
        Access::authorize($actor, Permission::P21);

        return LockingTransaction::run(function () use ($actor, $lockVersion, $source) {
            $flow = $this->flows->lock($lockVersion);
            Transition::check(StateMachine::M23, FlowStatus::transitions(), $flow->status, 'T03');

            $result = $this->check->run($flow->draft ?? []);
            $result->throwIfFails();

            $version = $this->provisioner->version($flow, $flow->template_key, $result->definition(), $actor);
            $flow->forceFill([
                'status' => FlowStatus::Published,
                'published_version_id' => $version->id,
                'draft' => null,
                'draft_by' => null,
                'draft_updated_at' => null,
                'lock_version' => $flow->lock_version + 1,
            ])->save();

            $this->transitions->record(StateMachine::M23, 'T03', $flow, FlowStatus::PublishedWithDraft, FlowStatus::Published, $actor, $source, context: [
                'version' => $version->number,
            ]);
            Audit::record(AuditAction::FlowPublished, $flow, $actor, after: ['version' => $version->number], source: $source);

            return $flow;
        });
    }
}
