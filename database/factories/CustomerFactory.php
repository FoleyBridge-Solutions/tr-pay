<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'client_id' => fake()->unique()->numerify('CLI-#####'),
            'client_key' => fake()->unique()->randomNumber(6),
            'mpc_customer_id' => null,
        ];
    }

    /**
     * Indicate customer has an MPC customer ID.
     */
    public function withMpcCustomerId(): static
    {
        return $this->state(fn (array $attributes) => [
            'mpc_customer_id' => fake()->numerify('MPC-######'),
        ]);
    }
}
