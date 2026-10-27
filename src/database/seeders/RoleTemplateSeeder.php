<?php

namespace Database\Seeders;

use App\Access\PermissionCatalogue;
use App\Models\RoleTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the default role templates (ر1..ر8) from the permission matrix. Idempotent and non-destructive:
 * a template that already exists (possibly edited by the owner) is left untouched.
 */
class RoleTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalogue::ROLES as $key => $role) {
            if (RoleTemplate::query()->where('key', $key)->exists()) {
                continue;
            }

            $template = RoleTemplate::query()->create([
                'key' => $key,
                'name_ar' => $role['ar'],
                'name_en' => $role['en'],
                'is_owner' => $key === 'owner',
            ]);

            foreach (PermissionCatalogue::forRole($key) as $permission) {
                $template->grants()->create([
                    'permission' => $permission,
                    'scope' => PermissionCatalogue::defaultScopeFor($permission, $key),
                ]);
            }
        }
    }
}
