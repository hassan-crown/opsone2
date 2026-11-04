<?php

namespace App\Automation;

use App\Models\AuditEvent;
use App\Models\Automation\AutomationEvent;
use App\Models\StateTransition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «Try it» for a draft rule (ADR-0042, analysis §4.2 dry run): the rule against the most recent real events of its
 * trigger — read from the same sources the recorder uses (transitions, audit events, the timer's live subjects) — with
 * what each action would do: the rendered text, the recipients, the task and its due time. It sends nothing and writes
 * nothing: the events are not saved, actions are only described, and the whole run is inside a transaction that is
 * rolled back.
 */
final class AutomationSimulator
{
    public const SAMPLES = 5;

    public function __construct(private readonly AutomationEngine $engine, private readonly AutomationActions $actions) {}

    /**
     * @param  array<string, mixed>  $rule  a normalized definition
     * @return list<array<string, mixed>>
     */
    public function run(array $rule): array
    {
        $out = [];
        DB::beginTransaction();
        try {
            foreach (self::recent($rule['trigger'], $rule['params'] ?? []) as $event) {
                $subject = AutomationSubject::of($event);
                [$outcome, $reason, $detail] = $this->engine->decide([...$rule, 'enabled' => true], $event, $subject, dryRun: true);
                $out[] = [
                    'event' => ['occurred_at' => $event->occurred_at->toIso8601String(), 'subject' => $subject->label(), 'order_id' => $subject->order?->id],
                    'outcome' => $outcome,
                    'reason' => $reason,
                    'conditions' => $detail['conditions'] ?? [],
                    'actions' => $outcome === 'matched'
                        ? array_map(fn (array $a) => $this->actions->describe($a, $subject, (string) $rule['name']['ar']), $rule['actions'])
                        : [],
                ];
            }
        } finally {
            DB::rollBack();
        }

        return $out;
    }

    /**
     * The latest real events of a trigger, as unsaved events.
     *
     * @param  array<string, mixed>  $params
     * @return list<AutomationEvent>
     */
    public static function recent(string $trigger, array $params, int $limit = self::SAMPLES): array
    {
        $definition = AutomationTriggers::get($trigger);
        if ($definition === null) {
            return [];
        }
        $events = [];
        $make = fn ($subject, string $source, ?int $sourceId, $at, array $context = []) => new AutomationEvent([
            'trigger' => $trigger, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
            'order_id' => AutomationTriggers::orderIdOf($subject), 'source' => $source, 'source_id' => $sourceId,
            'context' => $context, 'caused_by' => null, 'depth' => 0, 'occurred_at' => $at,
        ]);

        if ($definition['source'] === AutomationTriggers::TRANSITION) {
            $rows = StateTransition::query()->where('machine', $definition['machine'] ?? '')->where('transition', $definition['transition'] ?? '')
                ->orderByDesc('id')->limit($limit * 4)->get();
            foreach ($rows as $row) {
                $subject = AutomationRecorder::load($row->subject_type, $row->subject_id);
                if ($subject !== null && AutomationTriggers::accepts($trigger, $subject) && count($events) < $limit) {
                    $events[] = $make($subject, AutomationTriggers::TRANSITION, $row->id, $row->occurred_at);
                }
            }
        } elseif ($definition['source'] === AutomationTriggers::AUDIT) {
            $rows = AuditEvent::query()->where('action', $definition['action'] ?? '')->whereNotNull('subject_id')->orderByDesc('id')->limit($limit)->get();
            foreach ($rows as $row) {
                $subject = AutomationRecorder::load((string) $row->subject_type, (int) $row->subject_id);
                if ($subject !== null) {
                    $events[] = $make($subject, AutomationTriggers::AUDIT, $row->id, $row->occurred_at);
                }
            }
        } else {
            $param = (int) AutomationTriggers::param($trigger, $params);
            foreach (array_slice(AutomationTimers::due($trigger, $param, CarbonImmutable::now()), 0, $limit) as [$subject, , $context]) {
                $events[] = $make($subject, AutomationTriggers::TIMER, null, now(), $context);
            }
        }

        return $events;
    }
}
