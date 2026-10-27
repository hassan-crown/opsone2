<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare membership row. Prefer M-22-T00 (T00CreateMembership) when the transition log matters.
 *
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'job_title' => fake()->jobTitle(),
            'department_id' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => 'disabled', 'disabled_at' => now()]);
    }
}
