<?php

namespace App\Workflow;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\FlowStatus;
use App\Enums\StateMachine;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * M-23-T01 at provisioning: the facility starts with the default flow published (WF-01), so opening orders never
 * waits for setup. Idempotent; the `singleton` key keeps it to one flow even when two first orders race.
 */
final class FlowProvisioner
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    public function ensure(): Flow
    {
        $flow = Flow::current();
        if ($flow !== null) {
            return $flow;
        }

        try {
            return DB::transaction(fn () => $this->create(FlowTemplates::DEFAULT, null, TransitionRecorder::SOURCE_PROVISIONING));
        } catch (UniqueConstraintViolationException) {
            return Flow::current() ?? throw new \RuntimeException('The facility flow could not be provisioned.');
        }
    }

    /**
     * Publishes a template as a new version of the (new or existing) flow. Caller holds the transaction.
     */
    public function publishTemplate(?Flow $flow, string $template, ?User $actor, string $source, ?string $reason = null): Flow
    {
        $definition = ['stages' => FlowTemplates::definition($template)['stages']];

        if ($flow === null) {
            return $this->create($template, $actor, $source);
        }

        $from = $flow->status;
        $version = $this->version($flow, $template, $definition, $actor);
        $flow->forceFill([
            'status' => FlowStatus::Published,
            'published_version_id' => $version->id,
            'template_key' => $template,
            'draft' => null,
            'draft_by' => null,
            'draft_updated_at' => null,
            'lock_version' => $flow->lock_version + 1,
        ])->save();

        $this->transitions->record(StateMachine::M23, 'T01', $flow, $from, FlowStatus::Published, $actor, $source, $reason, [
            'template' => $template, 'version' => $version->number,
        ]);
        Audit::record(AuditAction::FlowTemplateChosen, $flow, $actor, before: ['version' => $version->number - 1], after: [
            'template' => $template, 'version' => $version->number,
        ], reason: $reason, source: $source);

        return $flow;
    }

    /**
     * @param  array{stages: list<array<string, mixed>>}  $definition
     */
    public function version(Flow $flow, string $template, array $definition, ?User $actor): FlowVersion
    {
        $number = (int) FlowVersion::query()->where('flow_id', $flow->id)->max('number') + 1;

        return FlowVersion::query()->create([
            'flow_id' => $flow->id,
            'number' => $number,
            'template_key' => $template,
            'definition' => $definition,
            'published_by' => $actor?->id,
            'published_at' => now(),
        ]);
    }

    private function create(string $template, ?User $actor, string $source): Flow
    {
        $flow = Flow::query()->create([
            'status' => FlowStatus::Published,
            'template_key' => $template,
        ]);
        $version = $this->version($flow, $template, ['stages' => FlowTemplates::definition($template)['stages']], $actor);
        $flow->forceFill(['published_version_id' => $version->id])->save();

        $this->transitions->record(StateMachine::M23, 'T01', $flow, null, FlowStatus::Published, $actor, $source, context: [
            'template' => $template, 'version' => 1,
        ]);

        return $flow;
    }
}
