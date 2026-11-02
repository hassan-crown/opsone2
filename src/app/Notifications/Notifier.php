<?php

namespace App\Notifications;

use App\Messages\MessageTemplates;
use App\Models\Facility;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Creates an in-app notification and, when the type and the user's preference allow, queues its email copy.
 * Call it inside the business transaction: both rows commit with it and the delivery job runs after commit.
 */
final class Notifier
{
    public function __construct(private readonly Outbox $outbox) {}

    /**
     * @param  array<string, scalar>  $params  non-sensitive values only; shown in the drawer and the email
     */
    public function send(User $user, string $type, array $params = [], ?Model $target = null, ?string $dedupeKey = null): ?UserNotification
    {
        $definition = NotificationCatalogue::get($type) ?? throw new InvalidArgumentException("Unknown notification type {$type}.");

        if ($dedupeKey !== null && UserNotification::query()->where('user_id', $user->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $facility = Facility::current();
        $locale = $user->locale ?? ($facility === null ? 'ar' : $facility->default_locale);
        // A facility wording (ADR-0037) is rendered now, in the recipient's language; otherwise the keys stay and the
        // screen translates them.
        $title = MessageTemplates::subject($type, 'in_app', $locale, $params) ?? $definition['title'];
        $body = MessageTemplates::find($type, 'in_app', $locale) === null ? $definition['body'] : MessageTemplates::body($type, 'in_app', $locale, $params, (string) $definition['body']);

        $notification = UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'data' => ['title' => $title, 'body' => $body, 'params' => $params],
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'dedupe_key' => $dedupeKey,
        ]);

        if ($this->wantsEmail($user, $type, $definition['mandatory'])) {
            $url = $definition['route'] !== null || $target !== null ? route('notifications.open', $notification) : null;

            $lines = [];
            if ($body !== null && $body !== '') {
                $lines[] = __($body, $params, $locale);
            }

            $this->outbox->enqueueMail(
                $user->email,
                __($title, $params, $locale),
                $lines,
                $url === null ? null : ['label' => __('notifications.mail.open', [], $locale), 'url' => $url],
                $locale,
                "notification:{$notification->id}:mail",
            );
        }

        return $notification;
    }

    private function wantsEmail(User $user, string $type, bool $mandatory): bool
    {
        if ($mandatory) {
            return true;
        }

        $preference = NotificationPreference::query()->where('user_id', $user->id)->where('type', $type)->first();

        return $preference === null || $preference->email;
    }
}
