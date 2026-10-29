<?php

namespace App\Support;

use Closure;

/**
 * Values read many times while one request (or queued job) is served, e.g. the facility time zone a calendar of 500
 * rows asked for 1,000 times (ADR-0027). Scoped: rebuilt for every request and job; also flushed after each handled
 * request (the test kernel keeps one container) and by the writers of a cached value.
 */
final class RequestCache
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public function remember(string $key, Closure $resolve): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $resolve();
        }

        return $this->values[$key];
    }

    public function flush(): void
    {
        $this->values = [];
    }
}
