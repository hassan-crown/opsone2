<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Interest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interest>
 */
class InterestFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(fn (Interest $interest) => $interest->forceFill(['reference' => sprintf('INT-%04d', $interest->id)])->save());
    }

    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(10000000, 99999999);

        return [
            'status' => 'new',
            'name' => 'مهتم '.$n,
            'mobile' => '+9665'.$n,
            'need' => 'مطبخ لشقة جديدة',
            'channel' => 'phone',
            'branch_id' => Branch::factory(),
        ];
    }
}
