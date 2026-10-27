<?php

namespace Database\Factories;

use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Region>
 */
class RegionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name_ar' => 'منطقة '.fake()->unique()->numberBetween(1, 9999),
            'name_en' => 'Region '.fake()->unique()->numberBetween(1, 9999),
            'status' => 'active',
        ];
    }
}
