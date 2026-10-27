<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database: facility profile, default role templates and owner;
     * the demo organization in the local environment only.
     */
    public function run(): void
    {
        $this->call([
            FacilitySeeder::class,
            RoleTemplateSeeder::class,
            OwnerSeeder::class,
            HelpSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
