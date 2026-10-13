<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Models\Resume;
use App\Models\Review;
use App\Models\User;
use App\PaymentStatus;
use App\ResumeExtractionStatus;
use App\ReviewStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReviewControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_review_endpoints_return_401_without_token(): void
    {
        $this->getJson('/api/v1/reviews')->assertUnauthorized();
        $this->postJson('/api/v1/reviews')->assertUnauthorized();
        $this->getJson('/api/v1/reviews/1')->assertUnauthorized();
        $this->get('/api/v1/reviews/1/download', ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_verified_payment_creates_completed_review_and_consumes_entitlement(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create([
            'extraction_status' => ResumeExtractionStatus::Succeeded,
            'extracted_text' => $this->strongResumeText(),
            'extracted_at' => now(),
        ]);
        $payment = Payment::factory()->for($user)->for($resume)->verified()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/reviews', [
            'payment_id' => $payment->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assessment_version', 'rule-based-v1')
            ->assertJsonPath('data.resume_id', $resume->id)
            ->assertJsonStructure([
                'data' => [
                    'overall_score',
                    'category_scores',
                    'weaknesses',
                    'recommendations',
                    'disclaimer',
                ],
            ]);

        $review = Review::query()->sole();

        $this->assertSame($payment->id, $review->payment_id);
        $this->assertSame(ReviewStatus::Completed, $review->status);
        $this->assertNotNull($payment->refresh()->entitlement_consumed_at);
    }

    public function test_review_entitlement_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create([
            'extraction_status' => ResumeExtractionStatus::Succeeded,
            'extracted_text' => $this->strongResumeText(),
        ]);
        $payment = Payment::factory()->for($user)->for($resume)->verified()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews', ['payment_id' => $payment->id])
            ->assertCreated();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews', ['payment_id' => $payment->id]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_id']);

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_returns_422_when_payment_is_not_verified(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->create([
            'status' => PaymentStatus::Initialized,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/reviews', [
            'payment_id' => $payment->id,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_id']);

        $this->assertDatabaseCount('reviews', 0);
        $this->assertNull($payment->refresh()->entitlement_consumed_at);
    }

    public function test_history_returns_only_authenticated_candidates_reviews_newest_first(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $olderPayment = Payment::factory()->for($user)->for($resume)->verified()->create();
        $newerPayment = Payment::factory()->for($user)->for($resume)->verified()->create();
        $olderReview = Review::factory()->for($user)->for($resume)->for($olderPayment)->create([
            'created_at' => now()->subDay(),
        ]);
        $newerReview = Review::factory()->for($user)->for($resume)->for($newerPayment)->create([
            'created_at' => now(),
        ]);
        Review::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/reviews');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newerReview->id)
            ->assertJsonPath('data.1.id', $olderReview->id);
    }

    public function test_returns_404_for_another_candidates_review(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews/'.$review->id)
            ->assertNotFound();
    }

    public function test_candidate_can_download_plain_text_review(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->verified()->create();
        $review = Review::factory()->for($user)->for($resume)->for($payment)->create([
            'overall_score' => 72,
            'assessment_version' => 'rule-based-v1',
            'category_scores' => ['contact_information' => 80],
            'weaknesses' => ['Contact information is incomplete.'],
            'recommendations' => ['Add a professional email address.'],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/v1/reviews/'.$review->id.'/download');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="careerprep-review-'.$review->id.'.txt"')
            ->assertSeeText('Overall score: 72/100')
            ->assertSeeText('rule-based-v1')
            ->assertSeeText('does not guarantee hiring outcomes');
    }

    public function test_index_filters_by_resume_within_the_candidates_reviews(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $review = Review::factory()->for($user)->for($resume)->create();
        Review::factory()->for($user)->create();
        $otherReview = Review::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews?resume_id='.$resume->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $review->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews?resume_id='.$otherReview->resume_id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_cross_origin_download_exposes_filename_header_to_the_frontend(): void
    {
        config()->set('cors.allowed_origins', ['https://frontend.example']);
        $user = User::factory()->create();
        $review = Review::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/reviews/'.$review->id.'/download', ['Origin' => 'https://frontend.example'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example')
            ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    private function strongResumeText(): string
    {
        return <<<'TEXT'
John Candidate
john@example.com | +234 801 234 5678 | linkedin.com/in/johncandidate

PROFESSIONAL SUMMARY
Backend engineer with six years of experience building reliable web applications, improving delivery processes, and collaborating with product teams to deliver measurable business outcomes.

WORK EXPERIENCE
Senior Backend Engineer, Example Ltd | 2021 - 2026
- Developed Laravel services that reduced response times by 35 percent.
- Led a team of four engineers and delivered twelve production releases.
- Implemented monitoring that increased service availability to 99.9 percent.
- Optimized PostgreSQL queries and reduced reporting time by 40 percent.

Software Engineer, Sample Co | 2018 - 2021
- Built internal tools used by more than 200 employees.
- Created automated tests that reduced escaped defects by 25 percent.

EDUCATION
Bachelor of Science in Computer Science, Example University, 2018

SKILLS
PHP, Laravel, PostgreSQL, SQL, Docker, AWS, JavaScript, communication, leadership, project management

PROJECTS
- Designed and delivered a secure candidate portal with documented APIs.
TEXT;
    }
}
