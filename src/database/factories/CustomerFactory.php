<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(10000000, 99999999);

        return [
            'kind' => 'person',
            'name' => 'عميل '.$n,
            'mobile' => '+9665'.$n,
            'email' => 'customer'.$n.'@example.test',
            'country' => 'SA',
            'branch_id' => Branch::factory(),
        ];
    }

    public function organization(): static
    {
        return $this->state(fn () => ['kind' => 'organization', 'commercial_registration' => '1010587342', 'vat_number' => '310458712300003']);
    }
}
