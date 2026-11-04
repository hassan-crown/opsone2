<?php

namespace App\Automation;

use Closure;

/**
 * The chain of rules whose actions are running now (ADR-0042, loop protection). While a rule's action executes, any
 * event it causes (a task's T00 transition, a lead's assignment) is recorded with this chain and its depth, so the
 * engine can refuse a rule that would re-trigger itself and any chain deeper than AutomationEngine::MAX_DEPTH.
 */
final class AutomationContext
{
    /** @var list<string> */
    private array $chain = [];

    /**
     * @template T
     *
     * @param  list<string>  $chain
     * @param  Closure(): T  $run
     * @return T
     */
    public function within(array $chain, Closure $run): mixed
    {
        $previous = $this->chain;
        $this->chain = $chain;
        try {
            return $run();
        } finally {
            $this->chain = $previous;
        }
    }

    /**
     * @return list<string>
     */
    public function chain(): array
    {
        return $this->chain;
    }
}
