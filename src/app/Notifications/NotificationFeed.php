<?php

namespace App\Notifications;

use App\Access\Access;
use App\Access\Permission;
use App\Models\User;
use App\Models\UserNotification;

/**
 * What the shell bell shows: the unread count and the latest notifications of the signed-in user. Polled by the
 * client (Q-26: no Reverb). Text keys and plain parameters only; links go through `notifications.open`.
 */
final class NotificationFeed
{
    public const LIMIT = 20;

    public function __construct(private readonly NotificationLinks $links) {}

    /**
     * @return array{unread: int, items: array<int, array<string, mixed>>}|null
     */
    public function for(?User $user): ?array
    {
        if ($user === null || ! Access::can($user, Permission::P64)) {
            return null;
        }

        $items = UserNotification::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (UserNotification $n) => [
                'id' => $n->id,
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? null,
                'params' => $n->data['params'] ?? new \stdClass,
                'read' => $n->read_at !== null,
                'linked' => $this->links->hasLink($n),
                'created_at' => $n->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'unread' => UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count(),
            'items' => $items,
        ];
    }
}
