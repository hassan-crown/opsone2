<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 99999);

        return [
            'region_id' => null,
            'code' => 'BR'.$n,
            'name_ar' => 'فرع '.$n,
            'name_en' => 'Branch '.$n,
            'status' => 'active',
        ];
    }
}
