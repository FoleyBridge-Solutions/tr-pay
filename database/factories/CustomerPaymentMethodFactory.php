<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerPaymentMethod>
 */
class CustomerPaymentMethodFactory extends Factory
{
    protected $model = CustomerPaymentMethod::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'mpc_token' => fake()->uuid(),
            'type' => CustomerPaymentMethod::TYPE_CARD,
            'last_four' => fake()->numerify('####'),
            'brand' => fake()->randomElement(['Visa', 'Mastercard', 'American Express', 'Discover']),
            'exp_month' => fake()->numberBetween(1, 12),
            'exp_year' => fake()->numberBetween(now()->year + 1, now()->year + 5),
            'bank_name' => null,
            'nickname' => null,
            'is_default' => false,
            'expiration_notified_at' => null,
        ];
    }

    /**
     * Create a card payment method.
     */
    public function card(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CustomerPaymentMethod::TYPE_CARD,
            'brand' => fake()->randomElement(['Visa', 'Mastercard', 'American Express', 'Discover']),
            'exp_month' => fake()->numberBetween(1, 12),
            'exp_year' => fake()->numberBetween(now()->year + 1, now()->year + 5),
            'bank_name' => null,
        ]);
    }

    /**
     * Create an ACH payment method.
     */
    public function ach(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CustomerPaymentMethod::TYPE_ACH,
            'brand' => null,
            'exp_month' => null,
            'exp_year' => null,
            'bank_name' => fake()->company().' Bank',
        ]);
    }

    /**
     * Create a Visa card.
     */
    public function visa(): static
    {
        return $this->card()->state(fn (array $attributes) => [
            'brand' => 'Visa',
        ]);
    }

    /**
     * Create a Mastercard.
     */
    public function mastercard(): static
    {
        return $this->card()->state(fn (array $attributes) => [
            'brand' => 'Mastercard',
        ]);
    }

    /**
     * Set as default payment method.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Create a card that's expiring soon (within 30 days).
     */
    public function expiringSoon(): static
    {
        $now = now();

        return $this->card()->state(fn (array $attributes) => [
            'exp_month' => $now->month,
            'exp_year' => $now->year,
        ]);
    }

    /**
     * Create an expired card.
     */
    public function expired(): static
    {
        $pastDate = now()->subMonths(2);

        return $this->card()->state(fn (array $attributes) => [
            'exp_month' => $pastDate->month,
            'exp_year' => $pastDate->year,
        ]);
    }

    /**
     * Set a nickname.
     */
    public function withNickname(?string $nickname = null): static
    {
        return $this->state(fn (array $attributes) => [
            'nickname' => $nickname ?? fake()->words(2, true),
        ]);
    }

    /**
     * Mark as notified about expiration.
     */
    public function expirationNotified(): static
    {
        return $this->state(fn (array $attributes) => [
            'expiration_notified_at' => now(),
        ]);
    }
}
