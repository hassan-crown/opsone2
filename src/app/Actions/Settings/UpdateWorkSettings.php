<?php

namespace App\Actions\Settings;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\User;
use App\Models\WorkSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Facility work settings (Q-31 a; P-02): working days/hours, appointment slot length, reminder send hours and the
 * «conflicting appointments» policy. Switching to «block» never cancels earlier appointments (SHR-03).
 */
final class UpdateWorkSettings
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $time = ['required', 'date_format:H:i'];

        return [
            'working_days' => ['required', 'array', 'min:1', 'max:7'],
            'working_days.*' => ['integer', 'distinct', 'between:1,7'],
            'work_starts_at' => $time,
            'work_ends_at' => $time,
            'appointment_slot_minutes' => ['required', 'integer', 'in:15,30,45,60,90,120,180,240'],
            'reminder_from' => $time,
            'reminder_until' => $time,
            'conflicting_appointments' => ['required', 'in:'.WorkSettings::ALLOW_WITH_REASON.','.WorkSettings::BLOCK],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, string $source = TransitionRecorder::SOURCE_WEB): WorkSettings
    {
        Access::authorize($actor, Permission::P02);
        $data = Validator::make($data, self::rules())->validate();
        if ($data['work_starts_at'] >= $data['work_ends_at']) {
            throw ValidationException::withMessages(['work_ends_at' => __('workflow.validation.hours_order')]);
        }
        if ($data['reminder_from'] >= $data['reminder_until']) {
            throw ValidationException::withMessages(['reminder_until' => __('workflow.validation.hours_order')]);
        }
        $data['working_days'] = array_values(array_map(intval(...), $data['working_days']));
        sort($data['working_days']);

        return DB::transaction(function () use ($actor, $data, $source) {
            $settings = WorkSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new WorkSettings(WorkSettings::DEFAULTS);
            $before = $settings->only(array_keys(WorkSettings::DEFAULTS));
            $settings->fill([...$data, 'updated_by' => $actor->id])->save();

            Audit::record(AuditAction::WorkSettingsUpdated, $settings, $actor, before: $before,
                after: $settings->only(array_keys(WorkSettings::DEFAULTS)), source: $source);

            return $settings;
        });
    }
}
