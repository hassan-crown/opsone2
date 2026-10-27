<?php

namespace Database\Seeders;

use App\Access\TransitionRecorder;
use App\Enums\SetupStatus;
use App\Enums\StateMachine;
use App\Models\Facility;
use Illuminate\Database\Seeder;

/**
 * Creates the single facility profile (M-02 starts «غير مكتمل»). Real data comes from the subscription
 * request during provisioning (domain 08); these are placeholders when it is absent.
 */
class FacilitySeeder extends Seeder
{
    public function run(TransitionRecorder $transitions): void
    {
        if (Facility::query()->exists()) {
            return;
        }

        $facility = Facility::query()->create([
            'name_ar' => (string) config('opsone.facility.name_ar'),
            'name_en' => config('opsone.facility.name_en'),
            'default_locale' => 'ar',
            'currency' => (string) config('opsone.facility.currency'),
            'timezone' => (string) config('opsone.facility.timezone'),
        ]);

        $transitions->record(StateMachine::M02, 'T00', $facility, null, SetupStatus::Incomplete, null, TransitionRecorder::SOURCE_SEEDER);
    }
}
