<?php

namespace Database\Seeders;

use Database\Seeders\ShowcaseKit\Showcase;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * LOCAL/DEMO ONLY showcase dataset: "OpsOne" (أوبس ون), a Saudi kitchen maker and accessories seller, as if it had been
 * running on the product for six months (2026-05-01 → 2026-11-01). Not part of DatabaseSeeder.
 *
 * Everything goes through the real domain Actions/services (numbering, ledger, stock, audit, notifications) while the
 * clock is walked forward with Carbon::setTestNow(), so every row carries its historical timestamp. Deterministic:
 * a fixed PRNG seed. Run on an EMPTY migrated database with a license issued since before the start date:
 *
 *   artisan license:dev-issue --plan=scale --starts=2026-04-01 --days=800
 *   artisan db:seed --class=ShowcaseSeeder
 *
 * `$scale` shrinks the volumes for tests (1.0 = the showcase); `$images` turns image attachments off.
 */
class ShowcaseSeeder extends Seeder
{
    public float $scale = 1.0;

    public bool $images = true;

    public bool $trace = false;

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('ShowcaseSeeder runs in the local and testing environments only.');
        }

        $this->call([FacilitySeeder::class, RoleTemplateSeeder::class, HelpSeeder::class]);

        $showcase = new Showcase($this->scale, $this->images, $this->command);
        $showcase->timeline->trace = $this->trace;
        try {
            $showcase->run();
        } finally {
            $showcase->finish();
        }
    }
}
