<?php

namespace Database\Seeders;

use App\Access\TransitionRecorder;
use App\Actions\Organization\EstablishOwner;
use App\Actions\StateMachines\M22Membership\T00CreateMembership;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Region;
use App\Models\RoleTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * LOCAL ONLY demo organization: one region, two branches, two departments and one member per default role.
 * Every demo account signs in with the password `password` (same as UserFactory). Never run outside local.
 */
class DemoSeeder extends Seeder
{
    public function run(EstablishOwner $establishOwner, T00CreateMembership $createMembership): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoSeeder runs in the local environment only.');
        }

        if (User::query()->where('email', 'owner@demo.test')->exists()) {
            return;
        }

        $region = Region::query()->create(['name_ar' => 'المنطقة الوسطى', 'name_en' => 'Central region']);
        $main = Branch::query()->create(['region_id' => $region->id, 'code' => 'RUH', 'name_ar' => 'فرع الرياض', 'name_en' => 'Riyadh branch']);
        $second = Branch::query()->create(['region_id' => $region->id, 'code' => 'KHJ', 'name_ar' => 'فرع الخرج', 'name_en' => 'Al-Kharj branch']);
        $production = Department::query()->create(['name_ar' => 'الإنتاج', 'name_en' => 'Production']);
        $production->branches()->sync([$main->id, $second->id]);
        $design = Department::query()->create(['name_ar' => 'التصميم', 'name_en' => 'Design']);
        $design->branches()->sync([$main->id]);

        $owner = User::factory()->create(['name' => 'مالك تجريبي', 'email' => 'owner@demo.test']);
        if (Facility::current()?->owner_membership_id === null) {
            $establishOwner->handle($owner, TransitionRecorder::SOURCE_SEEDER);
        }

        $members = [
            'manager' => [null, [$main->id, $second->id]],
            'supervisor' => [$production->id, [$main->id]],
            'sales' => [null, [$main->id]],
            'executor' => [$design->id, [$main->id]],
            'finance' => [null, [$main->id, $second->id]],
            'inventory' => [null, [$main->id]],
            'content' => [null, []],
        ];

        foreach ($members as $key => [$departmentId, $branchIds]) {
            $template = RoleTemplate::query()->where('key', $key)->firstOrFail();
            $user = User::factory()->create(['name' => $template->name_ar.' تجريبي', 'email' => $key.'@demo.test']);
            $createMembership->handle(
                $user, $departmentId, $branchIds, [['role_template_id' => $template->id]], $template->name_en, $owner, TransitionRecorder::SOURCE_SEEDER,
            );
        }
    }
}
