<?php

namespace App\Actions\FieldSales;

use App\Access\TransitionRecorder;
use App\Actions\Rules\RulesAccess;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Configuration\ConfigReleases;
use App\Models\FieldSales\FieldSalesSettings;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Settings › field sales (P-104, ADR-0041): on/off, the appointment kinds reps handle in the field app, the
 * response time of a dispatched lead and the ceiling on the discount limit a supervisor may give a rep. Saved to the draft; it applies once published as a configuration release
 * (ADR-0040), like the other business rules.
 */
final class UpdateFieldSalesSettings
{
    public const KEYS = ['enabled', 'kinds', 'response_hours', 'max_discount_bp'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'kinds' => ['present', 'array', 'max:2'],
            'kinds.*' => ['string', 'distinct', Rule::in(FieldSalesSettings::KINDS)],
            'response_hours' => ['nullable', 'integer', 'between:1,720'],
            'max_discount_bp' => ['sometimes', 'integer', 'between:0,5000'],
            'lock_version' => ['nullable', 'integer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, string $source = TransitionRecorder::SOURCE_WEB): FieldSalesSettings
    {
        RulesAccess::authorize($actor);
        $data = Validator::make($data, self::rules())->after(function ($v) use ($data) {
            if (($data['enabled'] ?? false) && ($data['kinds'] ?? []) === []) {
                $v->errors()->add('kinds', __('field_sales.validation.kinds'));
            }
        })->validate();

        return LockingTransaction::run(function () use ($actor, $data, $source) {
            $settings = FieldSalesSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new FieldSalesSettings(FieldSalesSettings::DEFAULTS);
            if ($settings->exists && isset($data['lock_version']) && (int) $data['lock_version'] !== $settings->lock_version) {
                throw new StaleRecord($settings->lock_version);
            }
            $before = $settings->exists ? $settings->only(self::KEYS) : [];
            $settings->forceFill([
                'enabled' => (bool) $data['enabled'],
                'kinds' => array_values(array_filter(FieldSalesSettings::KINDS, fn (string $k) => in_array($k, $data['kinds'], true))),
                'response_hours' => isset($data['response_hours']) ? (int) $data['response_hours'] : null,
                'max_discount_bp' => (int) ($data['max_discount_bp'] ?? $settings->max_discount_bp ?? 0),
                'updated_by' => $actor->id,
                'lock_version' => $settings->exists ? $settings->lock_version + 1 : 1,
            ])->save();
            Audit::record(AuditAction::FieldSalesSettingsUpdated, $settings, $actor, before: $before, after: $settings->only(self::KEYS), source: $source);
            ConfigReleases::forget();

            return $settings;
        });
    }
}
