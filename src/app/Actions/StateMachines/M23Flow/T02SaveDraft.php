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
use Illuminate\Validation\ValidationException;

/**
 * M-23-T02 (P-20): start or update the edit draft. New orders keep using the published version and running orders keep
 * their own; the draft is only checked in full when published (FlowCheck), so it may be saved half-done.
 */
final class T02SaveDraft
{
    public function __construct(private readonly FlowLoader $flows, private readonly TransitionRecorder $transitions) {}

    /**
     * @param  array<string, mixed>  $definition  `{stages: [...]}`
     */
    public function handle(User $actor, array $definition, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Flow
    {
        Access::authorize($actor, Permission::P20);
        if (! is_array($definition['stages'] ?? null) || strlen((string) json_encode($definition)) > 200_000) {
            throw ValidationException::withMessages(['stages' => __('workflow.check.invalid_structure')]);
        }

        return LockingTransaction::run(function () use ($actor, $definition, $lockVersion, $source) {
            $flow = $this->flows->lock($lockVersion);
            $from = $flow->status;
            Transition::check(StateMachine::M23, FlowStatus::transitions(), $from, 'T02');

            $flow->forceFill([
                'status' => FlowStatus::PublishedWithDraft,
                'draft' => ['stages' => array_values($definition['stages'])],
                'draft_by' => $actor->id,
                'draft_updated_at' => now(),
                'lock_version' => $flow->lock_version + 1,
            ])->save();

            // Each save is logged; the first one is the state change.
            $this->transitions->record(StateMachine::M23, 'T02', $flow, $from, FlowStatus::PublishedWithDraft, $actor, $source);
            Audit::record(AuditAction::FlowDraftSaved, $flow, $actor, after: ['stages' => count($definition['stages'])], source: $source);

            return $flow;
        });
    }
}
