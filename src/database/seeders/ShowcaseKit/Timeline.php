<?php

namespace Database\Seeders\ShowcaseKit;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * Chronological event queue: steps of every story are scheduled with the moment they happen and executed in time
 * order under a frozen clock, so gapless numbers (receipts, orders, deliveries) ascend with their dates.
 */
final class Timeline
{
    private \SplPriorityQueue $queue;

    private int $seq = 0;

    /** @var list<string> */
    public array $failures = [];

    public bool $trace = false;

    public function __construct(public readonly CarbonImmutable $end)
    {
        $this->queue = new \SplPriorityQueue;
        $this->queue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
    }

    public static function freeze(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    public function at(CarbonImmutable $when, Story $story, string $label, Closure $step): void
    {
        // Earlier first; equal moments keep insertion order (SplPriorityQueue pops the highest priority).
        $this->queue->insert([$when->getTimestamp(), $label, $step, $story], [-$when->getTimestamp(), -$this->seq++]);
    }

    public function run(?Closure $progress = null): void
    {
        while (! $this->queue->isEmpty()) {
            [$ts, $label, $step, $story] = $this->queue->extract();
            if ($ts > $this->end->getTimestamp()) {
                continue;
            }
            if (! $story->alive) {
                continue;
            }
            self::freeze(CarbonImmutable::createFromTimestamp($ts, $this->end->getTimezone()));
            if ($this->trace) {
                fwrite(STDERR, date('Y-m-d H:i', $ts).' '.$story->name.' '.$label.PHP_EOL);
            }
            try {
                $step($story);
            } catch (Throwable $e) {
                $story->alive = false;
                $where = '';
                foreach ($e->getTrace() as $frame) {
                    if (isset($frame['class']) && str_starts_with($frame['class'], 'App\\Actions')) {
                        $where = ' @'.class_basename($frame['class'] ?? '').'::'.($frame['function'] ?? '');
                        break;
                    }
                }
                $this->failures[] = $story->name.' › '.$label.': '.class_basename($e).' '.$e->getMessage().$where.' '.json_encode(array_intersect_key($story->data, array_flip(['discount', 'decision', 'quote_doc'])));
            }
            if ($progress !== null) {
                $progress($ts);
            }
        }
    }
}
