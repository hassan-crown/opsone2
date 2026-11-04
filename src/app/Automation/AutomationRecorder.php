<?php

namespace App\Automation;

use App\Jobs\ProcessAutomationEvent;
use App\Models\AuditEvent;
use App\Models\Automation\AutomationEvent;
use App\Models\StateTransition;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Turns the facts the application already records into automation events (ADR-0042). Hooked on the creation of a
 * `state_transitions` or `audit_events` row — both are written inside the transaction of the change — so the event
 * commits or rolls back with it; the processing job is dispatched after commit. Only triggers some release listens
 * to are recorded, so a facility without rules writes nothing. Each source fact is one event (`dedupe_key`).
 */
final class AutomationRecorder
{
    public function __construct(private readonly AutomationContext $context) {}

    public function transition(StateTransition $transition): void
    {
        $triggers = AutomationTriggers::forTransition($transition->machine->value, $transition->transition);
        if ($triggers === [] || array_intersect($triggers, AutomationRules::liveTriggers()) === []) {
            return;
        }
        $subject = self::load($transition->subject_type, $transition->subject_id);
        foreach ($triggers as $trigger) {
            if ($subject !== null && in_array($trigger, AutomationRules::liveTriggers(), true) && AutomationTriggers::accepts($trigger, $subject)) {
                $this->record($trigger, $subject, AutomationTriggers::TRANSITION, $transition->id, "transition:{$transition->id}:{$trigger}", $transition->occurred_at, $transition->context ?? []);
            }
        }
    }

    public function audit(AuditEvent $audit): void
    {
        $triggers = array_intersect(AutomationTriggers::forAudit($audit->action), AutomationRules::liveTriggers());
        if ($triggers === [] || $audit->subject_type === null || $audit->subject_id === null) {
            return;
        }
        $subject = self::load($audit->subject_type, $audit->subject_id);
        foreach ($triggers as $trigger) {
            if ($subject !== null) {
                $this->record($trigger, $subject, AutomationTriggers::AUDIT, $audit->id, "audit:{$audit->id}:{$trigger}", $audit->occurred_at, $audit->after ?? []);
            }
        }
    }

    /**
     * Writes one event (a repeat of the same key is ignored) and queues its processing after commit.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(string $trigger, Model $subject, string $source, ?int $sourceId, string $dedupeKey, ?CarbonInterface $at = null, array $context = []): ?AutomationEvent
    {
        $chain = $this->context->chain();
        $event = AutomationEvent::query()->firstOrCreate(['dedupe_key' => $dedupeKey], [
            'trigger' => $trigger,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'order_id' => AutomationTriggers::orderIdOf($subject),
            'source' => $source,
            'source_id' => $sourceId,
            'context' => array_intersect_key($context, array_flip(['order_id', 'stage_key', 'kind', 'param', 'due_on', 'remark', 'rule'])) ?: null,
            'caused_by' => $chain === [] ? null : $chain,
            'depth' => count($chain),
            'occurred_at' => $at ?? now(),
        ]);
        if (! $event->wasRecentlyCreated) {
            return null;
        }
        ProcessAutomationEvent::dispatch($event->id);

        return $event;
    }

    public static function load(string $type, int $id): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) && is_subclass_of($class, Model::class) ? $class::query()->find($id) : null;
    }
}
