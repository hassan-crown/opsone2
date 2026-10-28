<?php

namespace App\Actions\StateMachines\M23Flow;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Enums\FlowStatus;
use App\Enums\StateMachine;
use App\Models\Flow;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\FlowLoader;
use App\Workflow\FlowProvisioner;
use App\Workflow\FlowTemplates;
use App\Workflow\Transition;
use Illuminate\Validation\ValidationException;

/**
 * M-23-T01 (P-21): replace the published flow with another starting template after confirming the effect — it applies
 * to new orders only. An open draft must be resolved explicitly (`discardDraft`), never erased silently.
 */
final class T01ChooseTemplate
{
    public function __construct(private readonly FlowLoader $flows, private readonly FlowProvisioner $provisioner) {}

    public function handle(User $actor, string $template, bool $discardDraft = false, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Flow
    {
        Access::authorize($actor, Permission::P21);
        if (! in_array($template, FlowTemplates::keys(), true)) {
            throw ValidationException::withMessages(['template' => __('workflow.validation.template_unknown')]);
        }

        return LockingTransaction::run(function () use ($actor, $template, $discardDraft, $lockVersion, $source) {
            $flow = $this->flows->lock($lockVersion);

            if ($flow->status === FlowStatus::PublishedWithDraft) {
                if (! $discardDraft) {
                    Transition::refuse(StateMachine::M23, 'T01', 'draft_open');
                }
                Access::authorize($actor, Permission::P20);
                app(T04DiscardDraft::class)->discard($actor, $flow, $source);
            }
            Transition::check(StateMachine::M23, FlowStatus::transitions(), $flow->status, 'T01');

            return $this->provisioner->publishTemplate($flow, $template, $actor, $source);
        });
    }
}
