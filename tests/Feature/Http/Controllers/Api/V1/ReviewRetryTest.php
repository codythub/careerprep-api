<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Contracts\ResumeTextExtractor;
use App\Models\Payment;
use App\Models\Resume;
use App\Models\Review;
use App\Models\User;
use App\ResumeExtractionStatus;
use App\ReviewStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ReviewRetryTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var array<int, string|null> Extracted text per resume ID; null makes extraction fail. */
    private array $extractedText = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ResumeTextExtractor::class, new class($this->extractedText) implements ResumeTextExtractor
        {
            /** @param array<int, string|null> $texts */
            public function __construct(private array &$texts) {}

            public function extract(Resume $resume): string
            {
                return $this->texts[$resume->id] ?? throw new RuntimeException('Unreadable PDF.');
            }
        });
    }

    public function test_retry_endpoint_returns_401_without_token(): void
    {
        $this->postJson('/api/v1/reviews/1/retry')->assertUnauthorized();
    }

    public function test_failed_review_keeps_entitlement_consumed_and_reports_it_can_be_retried(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->verified()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews', ['payment_id' => $payment->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.attempts', 1)
            ->assertJsonPath('data.max_attempts', 3)
            ->assertJsonPath('data.can_retry', true);

        $this->assertNotNull($payment->refresh()->entitlement_consumed_at);
    }

    public function test_failed_review_can_be_retried_once_the_resume_becomes_readable(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->verified()->create();
        $review = $this->failedReview($user, $resume, $payment);
        $this->extractedText[$resume->id] = $this->resumeText();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.id', $review->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.attempts', 2)
            ->assertJsonPath('data.failure_reason', null)
            ->assertJsonPath('data.can_retry', false);

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_retry_can_switch_to_another_of_the_candidates_resumes(): void
    {
        $user = User::factory()->create();
        $unreadable = Resume::factory()->for($user)->create();
        $readable = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($unreadable)->verified()->create();
        $review = $this->failedReview($user, $unreadable, $payment);
        $this->extractedText[$readable->id] = $this->resumeText();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry', ['resume_id' => $readable->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.resume_id', $readable->id);

        $this->assertSame($unreadable->id, $payment->refresh()->resume_id);
    }

    public function test_retry_with_another_candidates_resume_returns_404_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->verified()->create();
        $review = $this->failedReview($user, $resume, $payment);
        $foreignResume = Resume::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry', ['resume_id' => $foreignResume->id])
            ->assertNotFound();

        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'resume_id' => $resume->id, 'attempts' => 1]);
    }

    public function test_cannot_retry_another_candidates_review(): void
    {
        $review = Review::factory()->create(['status' => ReviewStatus::Failed]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry')
            ->assertNotFound();
    }

    public function test_completed_review_cannot_be_retried(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review']);
    }

    public function test_duplicate_retry_after_success_is_rejected_without_reprocessing(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->verified()->create();
        $review = $this->failedReview($user, $resume, $payment);
        $this->extractedText[$resume->id] = $this->resumeText();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/reviews/'.$review->id.'/retry')->assertOk();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review']);

        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'attempts' => 2, 'status' => 'completed']);
    }

    public function test_review_still_processing_cannot_be_retried_until_it_is_stale(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->verified()->create();
        $review = Review::factory()->for($user)->for($resume)->for($payment)->create([
            'status' => ReviewStatus::Pending,
            'overall_score' => null,
            'completed_at' => null,
        ]);
        $this->extractedText[$resume->id] = $this->resumeText();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review' => 'still being processed']);

        $this->travel(11)->minutes();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_retries_stop_at_the_configured_attempt_limit(): void
    {
        config()->set('reviews.max_attempts', 2);
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->verified()->create();
        $review = $this->failedReview($user, $resume, $payment);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.attempts', 2)
            ->assertJsonPath('data.can_retry', false);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review' => 'no retry attempts remaining']);

        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'attempts' => 2]);
    }

    public function test_retry_does_not_restore_the_payment_entitlement(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->verified()->create();
        $this->failedReview($user, $resume, $payment);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews', ['payment_id' => $payment->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_id']);

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_payment_cannot_be_started_for_a_resume_whose_text_extraction_failed(): void
    {
        config()->set([
            'services.paystack.secret_key' => 'paystack-test-secret',
            'services.paystack.review_amount' => 500_000,
            'services.paystack.currency' => 'NGN',
        ]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create(['extraction_status' => ResumeExtractionStatus::Failed]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments', ['resume_id' => $resume->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resume_id']);

        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    private function failedReview(User $user, Resume $resume, Payment $payment): Review
    {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews', ['payment_id' => $payment->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed');

        return Review::query()->where('payment_id', $payment->id)->sole();
    }

    private function resumeText(): string
    {
        return <<<'TEXT'
Ada Candidate
ada@example.com | +234 801 234 5678 | linkedin.com/in/adacandidate

PROFESSIONAL SUMMARY
Backend engineer with six years of experience building reliable web applications and delivering measurable outcomes.

WORK EXPERIENCE
Senior Backend Engineer, Example Ltd | 2021 - 2026
- Developed Laravel services that reduced response times by 35 percent.

EDUCATION
BSc Computer Science, University of Lagos | 2018

SKILLS
PHP, Laravel, PostgreSQL, Testing
TEXT;
    }
}
