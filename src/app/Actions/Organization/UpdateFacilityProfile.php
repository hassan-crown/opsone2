<?php

namespace App\Actions\Organization;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P-02: edit the facility profile. The currency is locked after the first financial movement (currency_locked_at).
 * Input is expected to be validated by a Form Request; only the listed fields are accepted. When `version` (the
 * `updated_at` the form was loaded with) is given, a newer save is refused instead of overwritten (SRS-SET-05).
 */
final class UpdateFacilityProfile
{
    private const FIELDS = ['name_ar', 'name_en', 'contact_email', 'contact_phone', 'address', 'default_locale', 'currency', 'timezone'];

    public function __construct(private readonly Authorizer $access) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data): Facility
    {
        $facility = Facility::currentOrFail();
        $this->access->authorize($actor, Permission::P02, $facility);

        return DB::transaction(function () use ($actor, $facility, $data) {
            $locked = Facility::query()->lockForUpdate()->findOrFail($facility->id);
            if (isset($data['version']) && $locked->updated_at?->toIso8601String() !== $data['version']) {
                throw ValidationException::withMessages(['version' => __('account.profile.stale')]);
            }

            $changes = array_intersect_key($data, array_flip(self::FIELDS));

            if (isset($changes['currency']) && $changes['currency'] !== $locked->currency && $locked->currency_locked_at !== null) {
                throw ValidationException::withMessages(['currency' => __('validation.prohibited', ['attribute' => 'currency'])]);
            }

            $locked->fill($changes);
            $changed = array_keys($locked->getDirty());
            $before = array_intersect_key($locked->getOriginal(), array_flip($changed));
            $locked->save();
            if ($changed !== []) {
                Audit::record(AuditAction::FacilityProfileUpdated, $locked, $actor, before: $before, after: $locked->only($changed));
            }

            return $locked;
        });
    }
}
