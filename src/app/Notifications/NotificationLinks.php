<?php

namespace App\Notifications;

use App\Access\Access;
use App\Access\Permission;
use App\Models\User;
use App\Models\UserNotification;

/**
 * Resolves where a notification leads — or refuses. A link is only a pointer: the Gate is checked again for this
 * user on the live record every time it is opened, so a notification never re-grants access that was revoked or
 * never existed (SRS-SHR-05). A target without a catalogued permission is refused (deny by default).
 */
final class NotificationLinks
{
    public function __construct(private readonly NotificationTargets $targets) {}

    public function resolve(User $user, UserNotification $notification): ?string
    {
        $definition = NotificationCatalogue::get($notification->type);

        if ($definition === null || ! Access::can($user, Permission::P64)) {
            return null;
        }

        if ($notification->target_type !== null) {
            $permission = $definition['permission'];
            $record = $notification->target_id === null ? null : $this->targets->find($notification->target_type, $notification->target_id);

            if ($permission === null || $record === null || ! Access::can($user, $permission, $record)) {
                return null;
            }

            return $this->targets->url($notification->target_type, $record);
        }

        if ($definition['permission'] !== null && ! Access::can($user, $definition['permission'])) {
            return null;
        }

        return $definition['route'] === null ? null : route($definition['route']);
    }

    public function hasLink(UserNotification $notification): bool
    {
        $definition = NotificationCatalogue::get($notification->type);

        return $definition !== null && ($notification->target_type !== null || $definition['route'] !== null);
    }
}
