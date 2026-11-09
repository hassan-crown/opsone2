<?php

namespace App\Ai;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The facility's AI switches (P-02, ADR-0050 §5): one per feature, all off by default except the help assistant.
 * Stored in integration_settings (key `ai`) — no secret lives here. A switch only decides whether the facility wants
 * the feature; the license (`ai_core` / `ai_plus`, credits) and the deployment driver decide whether it can run.
 */
final class AiSettings
{
    public function row(): ?IntegrationSetting
    {
        return IntegrationSetting::query()->where('key', IntegrationSetting::AI)->first();
    }

    /**
     * @return array<string, bool> feature value => on
     */
    public function features(): array
    {
        $saved = $this->row()?->config['features'] ?? [];
        $saved = is_array($saved) ? $saved : [];

        $features = [];
        foreach (AiFeature::cases() as $feature) {
            $features[$feature->value] = array_key_exists($feature->value, $saved)
                ? (bool) $saved[$feature->value]
                : $feature->enabledByDefault();
        }

        return $features;
    }

    public function enabled(AiFeature $feature): bool
    {
        return $this->features()[$feature->value];
    }

    /**
     * @param  array<string, bool>  $features  unknown keys are ignored; missing keys keep their saved value
     */
    public function save(User $actor, array $features): IntegrationSetting
    {
        return DB::transaction(function () use ($actor, $features) {
            $before = $this->features();
            $after = $before;
            foreach (AiFeature::values() as $key) {
                if (array_key_exists($key, $features)) {
                    $after[$key] = (bool) $features[$key];
                }
            }

            $row = $this->row() ?? new IntegrationSetting(['key' => IntegrationSetting::AI, 'enabled' => true]);
            $row->forceFill(['config' => [...($row->config ?? []), 'features' => $after], 'updated_by' => $actor->id])->save();

            if ($before !== $after) {
                Audit::record(AuditAction::AiSettingsChanged, $row, $actor,
                    before: ['features' => array_diff_assoc($before, $after)],
                    after: ['features' => array_diff_assoc($after, $before)],
                    label: 'AI',
                );
            }

            return $row;
        });
    }
}
