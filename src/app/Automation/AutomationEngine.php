<?php

namespace App\Automation;

use App\Configuration\ConfigReleases;
use App\Models\Automation\AutomationActionLog;
use App\Models\Automation\AutomationEvent;
use App\Models\Automation\AutomationRule;
use App\Models\Automation\AutomationRun;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Evaluates one event against the rules of its release and runs the matching rules' actions (ADR-0042).
 *
 * - Release: an event about an order reads the release that order pinned (ADR-0040, `ConfigReleases::forOrder`);
 *   any other subject the current release.
 * - Per rule, one `automation_runs` row: matched, skipped (killed, disabled, conditions, loop, rate limit, subject
 *   gone) or failed — the execution log.
 * - Per action, one `automation_actions` row keyed «rule:event:index», written in the action's own transaction: a
 *   retried job never repeats an action that happened, and two workers racing on one event cannot both act.
 * - Loop protection: an event caused by a rule's own actions never re-triggers that rule, chains stop at MAX_DEPTH, and
 *   a rule acts at most MAX_PER_SUBJECT_DAY times a day for one subject.
 */
final class AutomationEngine
{
    public const MAX_DEPTH = 3;

    public const MAX_PER_SUBJECT_DAY = 5;

    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly AutomationActions $actions, private readonly AutomationContext $context) {}

    public function process(int $eventId): void
    {
        $event = AutomationEvent::query()->find($eventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }
        $event->forceFill(['attempts' => $event->attempts + 1])->save();
        [$snapshot, $releaseId] = $this->release($event);
        $subject = AutomationSubject::of($event);
        $failed = false;

        foreach (AutomationRules::forTrigger($snapshot, $event->trigger) as $rule) {
            if (! self::paramMatches($rule, $event)) {
                continue;
            }
            $run = AutomationRun::query()->where('automation_event_id', $event->id)->where('rule_key', $rule['key'])->first();
            if ($run !== null && $run->outcome !== AutomationRun::FAILED) {
                continue;
            }
            [$outcome, $reason, $detail] = $this->decide($rule, $event, $subject);
            $run ??= new AutomationRun(['automation_event_id' => $event->id, 'rule_key' => $rule['key']]);
            $run->forceFill([
                'config_release_id' => $releaseId, 'subject_type' => $event->subject_type, 'subject_id' => $event->subject_id,
                'outcome' => $outcome, 'reason' => $reason, 'detail' => $detail,
            ])->save();
            if ($outcome !== AutomationRun::MATCHED) {
                continue;
            }
            $results = $this->runActions($rule, $event, $subject, $run);
            $error = collect($results)->firstWhere('status', 'failed');
            $run->forceFill([
                'outcome' => $error === null ? AutomationRun::MATCHED : AutomationRun::FAILED,
                'reason' => $error === null ? null : 'action_failed',
                'detail' => [...$detail, 'actions' => $results],
            ])->save();
            $failed = $failed || $error !== null;
        }

        if ($failed && $event->attempts < self::MAX_ATTEMPTS) {
            throw new RuntimeException("Automation event {$event->id}: an action failed; retrying.");
        }
        $event->forceFill(['processed_at' => now()])->save();
    }

    /**
     * Whether the rule acts on this event, and why not. Pure apart from reading the kill switch and the run log.
     *
     * @param  array<string, mixed>  $rule
     * @return array{0: string, 1: string|null, 2: array<string, mixed>}
     */
    public function decide(array $rule, AutomationEvent $event, AutomationSubject $subject, bool $dryRun = false): array
    {
        if (! $dryRun && AutomationRule::query()->where('key', $rule['key'])->whereNotNull('killed_at')->exists()) {
            return [AutomationRun::SKIPPED, 'killed', []];
        }
        if (! ($rule['enabled'] ?? false)) {
            return [AutomationRun::SKIPPED, 'disabled', []];
        }
        if ($subject->record === null) {
            return [AutomationRun::SKIPPED, 'subject_missing', []];
        }
        if (in_array($rule['key'], $event->caused_by ?? [], true)) {
            return [AutomationRun::SKIPPED, 'loop_self', ['chain' => $event->caused_by]];
        }
        if ($event->depth >= self::MAX_DEPTH) {
            return [AutomationRun::SKIPPED, 'loop_depth', ['chain' => $event->caused_by]];
        }
        if (! $dryRun && AutomationRun::query()->where('rule_key', $rule['key'])->where('subject_type', $event->subject_type)->where('subject_id', $event->subject_id)
            ->where('outcome', AutomationRun::MATCHED)->where('created_at', '>=', now()->subDay())->count() >= self::MAX_PER_SUBJECT_DAY) {
            return [AutomationRun::SKIPPED, 'rate_limit', []];
        }
        $check = AutomationConditions::evaluate($rule['conditions'] ?? [], $subject->facts());

        return $check['passed']
            ? [AutomationRun::MATCHED, null, ['conditions' => $check['items']]]
            : [AutomationRun::SKIPPED, 'conditions', ['conditions' => $check['items']]];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<array{type: string, status: string, reason: string|null, summary: array<string, mixed>}>
     */
    private function runActions(array $rule, AutomationEvent $event, AutomationSubject $subject, AutomationRun $run): array
    {
        $results = [];
        $name = (string) ($rule['name']['ar'] ?? $rule['key']);
        $chain = [...($event->caused_by ?? []), $rule['key']];
        foreach ($rule['actions'] as $i => $action) {
            $key = "automation:{$rule['key']}:{$event->id}:{$i}";
            $done = AutomationActionLog::query()->where('idempotency_key', $key)->first();
            if ($done !== null && $done->status !== 'failed') {
                $results[] = ['type' => $action['type'], 'status' => $done->status, 'reason' => $done->result['reason'] ?? null, 'summary' => $done->result['summary'] ?? []];

                continue;
            }
            try {
                $outcome = $this->context->within($chain, fn () => DB::transaction(function () use ($action, $subject, $rule, $name, $key, $run, $done) {
                    $outcome = $this->actions->execute($action, $subject, $rule['key'], $name, $key);
                    $done?->delete();
                    AutomationActionLog::query()->create([
                        'automation_run_id' => $run->id, 'idempotency_key' => $key, 'type' => $action['type'], 'status' => $outcome['status'],
                        'result' => ['reason' => $outcome['reason'], 'summary' => $outcome['summary']],
                    ]);

                    return $outcome;
                }));
            } catch (Throwable $e) {
                Log::warning('automation.action_failed', ['rule' => $rule['key'], 'event' => $event->id, 'action' => $action['type'], 'error' => $e::class]);
                $outcome = ['status' => 'failed', 'reason' => 'error', 'summary' => ['error' => class_basename($e)]];
                AutomationActionLog::query()->updateOrCreate(['idempotency_key' => $key], [
                    'automation_run_id' => $run->id, 'type' => $action['type'], 'status' => 'failed', 'result' => ['reason' => 'error', 'summary' => $outcome['summary']],
                ]);
            }
            $results[] = ['type' => $action['type'], ...$outcome];
        }

        return $results;
    }

    /**
     * The release an event follows: its order's pinned release, else the current one.
     *
     * @return array{0: array<string, mixed>, 1: int|null}
     */
    public function release(AutomationEvent $event): array
    {
        if ($event->order_id !== null) {
            $pinned = Order::query()->whereKey($event->order_id)->value('config_release_id');

            return [ConfigReleases::forOrder($event->order_id), $pinned === null ? ConfigReleases::currentId() : (int) $pinned];
        }

        return [ConfigReleases::current(), ConfigReleases::currentId()];
    }

    /**
     * A timer event carries the parameter it was raised for; only rules with that parameter act on it.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function paramMatches(array $rule, AutomationEvent $event): bool
    {
        $param = AutomationTriggers::param($rule['trigger'], $rule['params'] ?? []);

        return $param === null || (int) ($event->context['param'] ?? -1) === $param;
    }
}
