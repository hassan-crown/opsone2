<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Facility work settings (Q-31 a, SHR-03): working days and hours, appointment slot length, reminder send hours and
 * the «conflicting appointments» policy (P-02). One row; current() falls back to the defaults before it is saved.
 *
 * @property list<int> $working_days ISO weekdays, 1 = Monday .. 7 = Sunday
 * @property string $work_starts_at HH:MM
 * @property string $work_ends_at HH:MM
 * @property int $appointment_slot_minutes
 * @property string $reminder_from HH:MM
 * @property string $reminder_until HH:MM
 * @property string $conflicting_appointments allow_with_reason | block
 * @property int|null $updated_by
 */
class WorkSettings extends Model
{
    public const ALLOW_WITH_REASON = 'allow_with_reason';

    public const BLOCK = 'block';

    /** Saudi working week (Sunday–Thursday), 08:00–17:00, one-hour slots, reminders 09:00–20:00, conflicts allowed with a reason. */
    public const DEFAULTS = [
        'working_days' => [7, 1, 2, 3, 4],
        'work_starts_at' => '08:00',
        'work_ends_at' => '17:00',
        'appointment_slot_minutes' => 60,
        'reminder_from' => '09:00',
        'reminder_until' => '20:00',
        'conflicting_appointments' => self::ALLOW_WITH_REASON,
    ];

    protected $guarded = ['id'];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self(self::DEFAULTS);
    }

    public function allowsConflicts(): bool
    {
        return $this->conflicting_appointments === self::ALLOW_WITH_REASON;
    }

    public function isWorkingDay(CarbonInterface $date): bool
    {
        return in_array($date->isoWeekday(), $this->working_days, true);
    }

    /**
     * Whether a reminder may be sent at this moment (facility time zone, reminder hours, any day).
     */
    public function canSendReminderAt(CarbonInterface $moment): bool
    {
        $local = $moment->copy()->setTimezone(Facility::current()?->timezone ?: config('app.timezone'))->format('H:i');

        return $local >= $this->reminder_from && $local < $this->reminder_until;
    }

    protected function casts(): array
    {
        return [
            'working_days' => 'array',
            'appointment_slot_minutes' => 'integer',
        ];
    }
}
