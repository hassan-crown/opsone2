<?php

namespace App\Models;

use App\Configuration\ConfigReleases;
use Illuminate\Database\Eloquent\Model;

/**
 * Collection reminder policy and the appointment reminder lead time (ADR-0037, P-104). Reminders go out only inside
 * the reminder hours (WorkSettings) and through the outbox. One row; current() falls back to the defaults, which
 * reproduce the behaviour before the policy existed (2 days before, weekly after) with a cap and no escalation.
 *
 * @property list<int> $before_days days before the due date, e.g. [7, 2]
 * @property bool $on_due a reminder on the due date itself
 * @property int $after_every_days interval of overdue reminders
 * @property int $max_after overdue reminders at most, per due and due date (0 = none)
 * @property int|null $escalate_after_days days overdue before staff are alerted (null = never)
 * @property string|null $escalate_to `order_assignee` or a role template key
 * @property int $appointment_hours WhatsApp/SMS reminder lead time before a confirmed appointment
 * @property int $lock_version
 * @property int|null $updated_by
 */
class ReminderSettings extends Model
{
    public const ORDER_ASSIGNEE = 'order_assignee';

    public const DEFAULTS = [
        'before_days' => [2],
        'on_due' => true,
        'after_every_days' => 7,
        'max_after' => 4,
        'escalate_after_days' => null,
        'escalate_to' => null,
        'appointment_hours' => 24,
    ];

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1];

    /**
     * The policy in force: the current configuration release's (ADR-0040).
     */
    public static function current(): self
    {
        return new self(ConfigReleases::current()['reminders']);
    }

    /**
     * The draft the settings page edits.
     */
    public static function working(): self
    {
        return self::query()->orderBy('id')->first() ?? new self(self::DEFAULTS);
    }

    protected function casts(): array
    {
        return [
            'before_days' => 'array',
            'on_due' => 'boolean',
            'after_every_days' => 'integer',
            'max_after' => 'integer',
            'escalate_after_days' => 'integer',
            'appointment_hours' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
