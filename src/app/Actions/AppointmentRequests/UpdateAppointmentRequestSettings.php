<?php

namespace App\Actions\AppointmentRequests;

use App\Access\TransitionRecorder;
use App\Actions\Rules\RulesAccess;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Appointment;
use App\Models\AppointmentRequestSettings;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Settings › appointment requests (P-104, ADR-0039): where customers may ask (site, workspace), which kinds, each
 * configurable field off / optional / required and the assignee's response time (1..168 hours, or none). Applies to
 * the next form shown and the next assignment; requests already assigned keep their due time.
 */
final class UpdateAppointmentRequestSettings
{
    public const KEYS = ['site_enabled', 'portal_enabled', 'kinds', 'fields', 'response_hours'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = [
            'site_enabled' => ['required', 'boolean'],
            'portal_enabled' => ['required', 'boolean'],
            'kinds' => ['present', 'array', 'max:'.count(Appointment::KINDS)],
            'kinds.*' => ['string', 'distinct', 'in:'.implode(',', Appointment::KINDS)],
            'fields' => ['required', 'array'],
            'response_hours' => ['nullable', 'integer', 'between:1,168'],
        ];
        foreach (AppointmentRequestSettings::FIELDS as $field) {
            $rules["fields.$field"] = ['required', 'in:'.implode(',', AppointmentRequestSettings::MODES)];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): AppointmentRequestSettings
    {
        RulesAccess::authorize($actor);
        $data = Validator::make($data, self::rules())->validate();
        // Kept in the catalogue's order, so the form lists them the same way everywhere.
        $kinds = array_values(array_intersect(Appointment::KINDS, $data['kinds']));
        $fields = [];
        foreach (AppointmentRequestSettings::FIELDS as $field) {
            $fields[$field] = (string) $data['fields'][$field];
        }

        return DB::transaction(function () use ($actor, $data, $kinds, $fields, $lockVersion, $source) {
            $settings = AppointmentRequestSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new AppointmentRequestSettings(AppointmentRequestSettings::DEFAULTS);
            if ($settings->exists && $lockVersion !== null && $lockVersion !== $settings->lock_version) {
                throw new StaleRecord($settings->lock_version);
            }
            $old = $settings->exists ? $settings->only(self::KEYS) : [];
            $settings->forceFill([
                'site_enabled' => (bool) $data['site_enabled'], 'portal_enabled' => (bool) $data['portal_enabled'],
                'kinds' => $kinds, 'fields' => $fields,
                'response_hours' => $data['response_hours'] === null ? null : (int) $data['response_hours'],
                'updated_by' => $actor->id, 'lock_version' => $settings->exists ? $settings->lock_version + 1 : 1,
            ])->save();
            Audit::record(AuditAction::AppointmentRequestSettingsUpdated, $settings, $actor, before: $old, after: $settings->only(self::KEYS), source: $source);

            return $settings;
        });
    }
}
