<?php

namespace App\Actions\Rules;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\User;
use App\Models\WorkSettings;
use App\Workflow\Catalogue\ActionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Task SLA settings (P-104, ADR-0037): on/off, default working days, days per action type, holidays (up to 60
 * dates) and the working days late before escalation. The working week itself stays in the work settings (P-02).
 * New tasks only: existing tasks keep their due time.
 */
final class UpdateTaskSla
{
    public const KEYS = ['holidays', 'task_sla', 'task_escalate_after_days'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'default_days' => ['required', 'integer', 'between:0,60'],
            'by_type' => ['present', 'array'],
            'by_type.*' => ['nullable', 'integer', 'between:0,60'],
            'holidays' => ['present', 'array', 'max:60'],
            'holidays.*' => ['date_format:Y-m-d', 'distinct'],
            'escalate_after_days' => ['required', 'integer', 'between:1,30'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, string $source = TransitionRecorder::SOURCE_WEB): WorkSettings
    {
        RulesAccess::authorize($actor);
        $data = Validator::make($data, self::rules())->validate();
        $types = array_map(fn (ActionType $t) => $t->value, ActionType::cases());
        $byType = array_map(intval(...), array_filter(array_intersect_key($data['by_type'], array_flip($types)), fn ($v) => $v !== null));
        $holidays = array_values(array_unique($data['holidays']));
        sort($holidays);

        return DB::transaction(function () use ($actor, $data, $byType, $holidays, $source) {
            $settings = WorkSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new WorkSettings(WorkSettings::DEFAULTS);
            $before = $settings->only(self::KEYS);
            $settings->fill([
                'holidays' => $holidays,
                'task_sla' => ['enabled' => (bool) $data['enabled'], 'default' => (int) $data['default_days'], 'by_type' => $byType],
                'task_escalate_after_days' => (int) $data['escalate_after_days'],
                'updated_by' => $actor->id,
            ])->save();
            Audit::record(AuditAction::TaskSlaSettingsUpdated, $settings, $actor, before: $before, after: $settings->only(self::KEYS), source: $source);

            return $settings;
        });
    }
}
