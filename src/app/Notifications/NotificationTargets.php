<?php

namespace App\Notifications;

use Closure;

/**
 * Maps a notification's `target_type` to the record it points at and the page that shows it. Domain phases
 * register their record types; the notification itself stores only type and id, never a URL or a permission.
 */
final class NotificationTargets
{
    /**
     * @var array<string, array{find: Closure(int): ?object, url: Closure(object): string}>
     */
    private array $targets = [];

    /**
     * @param  Closure(int): ?object  $find
     * @param  Closure(object): string  $url
     */
    public function register(string $type, Closure $find, Closure $url): void
    {
        $this->targets[$type] = ['find' => $find, 'url' => $url];
    }

    public function find(string $type, int $id): ?object
    {
        return isset($this->targets[$type]) ? ($this->targets[$type]['find'])($id) : null;
    }

    public function url(string $type, object $record): ?string
    {
        return isset($this->targets[$type]) ? ($this->targets[$type]['url'])($record) : null;
    }
}
