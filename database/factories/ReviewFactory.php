<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Resume;
use App\Models\Review;
use App\Models\User;
use App\ReviewStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
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
            'payment_id' => fn (array $attributes): int => Payment::factory()
                ->for(User::query()->findOrFail($attributes['user_id']))
                ->for(Resume::query()->findOrFail($attributes['resume_id']))
                ->verified()
                ->create()
                ->id,
            'status' => ReviewStatus::Completed,
            'assessment_version' => 'rule-based-v1',
            'overall_score' => 72,
            'category_scores' => ['contact_information' => 80],
            'weaknesses' => ['The professional summary is missing.'],
            'recommendations' => ['Add a concise professional summary.'],
            'completed_at' => now(),
        ];
    }
}
