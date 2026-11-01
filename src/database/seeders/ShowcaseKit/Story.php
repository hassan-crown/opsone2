<?php

namespace Database\Seeders\ShowcaseKit;

/**
 * One scenario (an order's life, a ticket…): the shared state its timed steps read and write.
 * A failed step ends the story (later steps are skipped) and is reported, never fatal.
 */
final class Story
{
    public bool $alive = true;

    /** @var array<string, mixed> */
    public array $data = [];

    public function __construct(public readonly string $name) {}

    public function int(string $key): int
    {
        return (int) ($this->data[$key] ?? 0);
    }
}
