<?php

namespace App\EInvoicing;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\EInvoicing\ZatcaDevice;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the seller's tax profile and switches (ADR-0032): `lock_version` against concurrent edits, enabling only with a
 * complete profile (and the first enable time remembered: invoices are owed for payments from then on), and an
 * environment change retires the live device (its CSID belongs to the old environment).
 */
final class ZatcaProfile
{
    public function __construct(private readonly ZatcaOnboarding $onboarding) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, array $data, ?int $lockVersion): ZatcaSettings
    {
        return DB::transaction(function () use ($actor, $data, $lockVersion) {
            $settings = ZatcaSettings::query()->orderBy('id')->lockForUpdate()->first() ?? new ZatcaSettings;
            if ($settings->exists && $lockVersion !== null && $lockVersion !== $settings->lock_version) {
                throw new StaleRecord($settings->lock_version);
            }
            $before = $settings->exists ? $settings->only([...ZatcaSettings::PROFILE, 'enabled', 'environment']) : [];

            $settings->fill(array_intersect_key($data, array_flip(ZatcaSettings::PROFILE)));
            $environmentChanged = $settings->exists && isset($data['environment']) && $data['environment'] !== $settings->environment;
            if (isset($data['environment'])) {
                $settings->environment = (string) $data['environment'];
            }
            $enabled = (bool) ($data['enabled'] ?? $settings->enabled);
            if ($enabled && ! $settings->profileComplete()) {
                throw ValidationException::withMessages(['enabled' => __('einvoicing.blocked.profile_incomplete')]);
            }
            $settings->enabled = $enabled;
            if ($enabled && $settings->enabled_at === null) {
                $settings->enabled_at = now();
            }
            $settings->updated_by = $actor->id;
            if ($settings->exists) {
                $settings->lock_version = $settings->lock_version + 1;
            }
            $settings->save();

            if ($environmentChanged && ZatcaDevice::live() !== null) {
                $this->onboarding->retireLive($actor, 'environment_changed');
            }

            Audit::record(AuditAction::ZatcaSettingsSaved, $settings, $actor, before: $before,
                after: $settings->only([...ZatcaSettings::PROFILE, 'enabled', 'environment']), label: (string) $settings->vat_number);

            return $settings;
        });
    }
}
