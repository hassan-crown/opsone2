<?php

namespace App\Actions\Rules;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\ReminderSettings;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Collection reminder policy (P-103, ADR-0037): up to 3 «before» reminders, one on the due date, up to 6 overdue
 * reminders at a fixed interval, optional escalation to the order's responsible person or a role after N overdue
 * days, and the appointment reminder lead time (1..168 hours). It applies to the next scheduler run.
 */
final class UpdateReminderSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'before_days' => ['present', 'array', 'max:3'],
            'before_days.*' => ['integer', 'distinct', 'between:1,30'],
            'on_due' => ['required', 'boolean'],
            'after_every_days' => ['required', 'integer', 'between:1,30'],
            'max_after' => ['required', 'integer', 'between:0,6'],
            'escalate_after_days' => ['nullable', 'integer', 'between:1,90'],
            'escalate_to' => ['nullable', 'required_with:escalate_after_days', 'string', 'max:32'],
            'appointment_hours' => ['required', 'integer', 'between:1,168'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): ReminderSettings
    {
        RulesAccess::authorize($actor);
        $data = Validator::make($data, self::rules())->validate();
        $to = $data['escalate_after_days'] === null ? null : $data['escalate_to'];
        if ($to !== null && $to !== ReminderSettings::ORDER_ASSIGNEE && ! RoleTemplate::query()->where('key', $to)->exists()) {
            throw ValidationException::withMessages(['escalate_to' => __('rules.reminders.validation.role')]);
        }
        $before = array_values(array_map(intval(...), $data['before_days']));
        rsort($before);

        return DB::transaction(function () use ($actor, $data, $to, $before, $lockVersion, $source) {
            $settings = ReminderSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new ReminderSettings(ReminderSettings::DEFAULTS);
            if ($settings->exists && $lockVersion !== null && $lockVersion !== $settings->lock_version) {
                throw new StaleRecord($settings->lock_version);
            }
            $old = $settings->exists ? $settings->only(array_keys(ReminderSettings::DEFAULTS)) : [];
            $settings->forceFill([
                'before_days' => $before, 'on_due' => (bool) $data['on_due'], 'after_every_days' => (int) $data['after_every_days'],
                'max_after' => (int) $data['max_after'], 'escalate_after_days' => $data['escalate_after_days'], 'escalate_to' => $to,
                'appointment_hours' => (int) $data['appointment_hours'], 'updated_by' => $actor->id,
                'lock_version' => $settings->exists ? $settings->lock_version + 1 : 1,
            ])->save();
            Audit::record(AuditAction::ReminderSettingsUpdated, $settings, $actor, before: $old, after: $settings->only(array_keys(ReminderSettings::DEFAULTS)), source: $source);

            return $settings;
        });
    }
}
