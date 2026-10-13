<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Resume;
use App\Models\User;
use App\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'resume_id' => fn (array $attributes): int => Resume::factory()
                ->for(User::query()->findOrFail($attributes['user_id']))
                ->create()
                ->id,
            'reference' => 'CP-'.Str::ulid(),
            'amount' => 500_000,
            'currency' => 'NGN',
            'status' => PaymentStatus::Initialized,
            'authorization_url' => 'https://checkout.paystack.com/'.Str::random(12),
            'access_code' => Str::random(12),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Verified,
            'provider_transaction_id' => (string) fake()->numberBetween(1_000_000, 9_999_999),
            'verified_at' => now(),
        ]);
    }
}
