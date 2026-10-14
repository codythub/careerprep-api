<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Admin;

use App\Models\AdminAuditLog;
use App\Models\Payment;
use App\Models\Resume;
use App\Models\Review;
use App\Models\User;
use App\PaymentStatus;
use App\ResumeExtractionStatus;
use App\ReviewStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminReviewStatsAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Site Admin', 'email' => 'admin@example.com']);
    }

    public function test_reviews_can_be_filtered_and_shown_with_candidate_fields(): void
    {
        $failed = $this->failedReview();
        Review::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/reviews?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $failed->id)
            ->assertJsonPath('data.0.user.id', $failed->user_id);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/reviews?user_id='.$failed->user_id)->assertJsonCount(1, 'data');
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/reviews?resume_id='.$failed->resume_id)->assertJsonCount(1, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/reviews/'.$failed->id)
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'resume_id', 'payment_id', 'resume_name', 'status', 'assessment_version', 'overall_score',
                'category_scores', 'weaknesses', 'recommendations', 'failure_reason', 'attempts', 'max_attempts',
                'can_retry', 'disclaimer', 'completed_at', 'created_at',
                'user_id', 'user' => ['id', 'name', 'email'], 'extra_attempts', 'payment_status', 'updated_at',
            ]])
            ->assertJsonPath('data.can_retry', true)
            ->assertJsonPath('data.payment_status', 'verified');
    }

    public function test_admin_retry_overrides_an_exhausted_attempt_limit_and_is_audited(): void
    {
        config()->set('reviews.max_attempts', 1);
        $review = $this->failedReview(readable: true);

        $this->actingAs($review->user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review' => 'no retry attempts remaining']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/reviews/'.$review->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.attempts', 2);

        $log = AdminAuditLog::query()->sole();
        $this->assertSame('review.retry', $log->action);
        $this->assertSame(['status' => 'failed', 'resume_id' => $review->resume_id, 'attempts' => 1, 'max_attempts' => 1], $log->before);
        $this->assertSame('completed', $log->after['status']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/reviews/'.$review->id.'/retry')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review' => 'already been completed']);
    }

    public function test_admin_retry_can_switch_resume_only_within_the_same_candidate(): void
    {
        $review = $this->failedReview();
        $ownResume = $this->readableResume($review->user);
        $otherResume = $this->readableResume(User::factory()->create());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/reviews/'.$review->id.'/retry', ['resume_id' => $otherResume->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resume_id']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/reviews/'.$review->id.'/retry', ['resume_id' => $ownResume->id])
            ->assertOk()
            ->assertJsonPath('data.resume_id', $ownResume->id)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_grant_attempt_lets_the_candidate_retry_again(): void
    {
        config()->set('reviews.max_attempts', 1);
        $review = $this->failedReview(readable: true);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/reviews/'.$review->id.'/grant-attempt')
            ->assertOk()
            ->assertJsonPath('data.extra_attempts', 1)
            ->assertJsonPath('data.max_attempts', 2)
            ->assertJsonPath('data.can_retry', true);

        $log = AdminAuditLog::query()->sole();
        $this->assertSame('review.grant_attempt', $log->action);
        $this->assertSame(1, $log->before['max_attempts']);
        $this->assertSame(2, $log->after['max_attempts']);

        $this->actingAs($review->user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_grant_attempt_is_rejected_for_completed_reviews(): void
    {
        $review = Review::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/reviews/'.$review->id.'/grant-attempt')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review']);

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_stats_count_activity_and_net_revenue_for_a_period(): void
    {
        config()->set('reviews.max_attempts', 2);
        $verified = Payment::factory()->verified()->create(['amount' => 600_000, 'currency' => 'NGN']);
        Payment::factory()->verified()->create(['amount' => 600_000, 'currency' => 'NGN']);
        Payment::factory()->create();
        $refunded = Payment::factory()->verified()->create(['amount' => 600_000, 'currency' => 'NGN']);
        $refunded->update(['status' => PaymentStatus::Refunded, 'refunded_at' => now()]);
        Review::factory()->for($verified->user)->for($verified->resume)->for($verified)->create(['status' => ReviewStatus::Failed]);
        $old = Payment::factory()->verified()->create(['amount' => 600_000, 'created_at' => now()->subDays(40), 'verified_at' => now()->subDays(40)]);
        Review::factory()->for($old->user)->for($old->resume)->for($old)->create(['status' => ReviewStatus::Completed, 'created_at' => now()->subDays(40)]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/stats')
            ->assertOk()
            ->assertJsonPath('data.payments.total', 5)
            ->assertJsonPath('data.payments.verified', 3)
            ->assertJsonPath('data.payments.refunded', 1)
            ->assertJsonPath('data.payments.initialized', 1)
            ->assertJsonPath('data.reviews.total', 2)
            ->assertJsonPath('data.reviews.failed', 1)
            ->assertJsonPath('data.reviews.retryable_failed', 1)
            ->assertJsonPath('data.users.admin', 1)
            ->assertJsonPath('data.revenue', [['currency' => 'NGN', 'amount' => 1_800_000, 'payments' => 3]])
            ->assertJsonPath('data.refunds', [['currency' => 'NGN', 'amount' => 600_000, 'payments' => 1]])
            ->assertJsonPath('data.period', ['from' => null, 'to' => null]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/stats?from='.now()->subDays(7)->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.payments.total', 4)
            ->assertJsonPath('data.reviews.total', 1)
            ->assertJsonPath('data.revenue.0.amount', 1_200_000)
            ->assertJsonPath('data.resumes.total', 5); // the backdated payment's resume was created today

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/stats?from=13-10-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from']);
    }

    public function test_audit_log_lists_newest_first_and_filters(): void
    {
        $otherAdmin = User::factory()->admin()->create();
        $review = $this->failedReview();
        $payment = Payment::factory()->verified()->create();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/reviews/'.$review->id.'/grant-attempt')->assertOk();
        $this->travel(1)->minutes();
        $this->actingAs($otherAdmin, 'sanctum')->postJson('/api/v1/admin/payments/'.$payment->id.'/mark-refunded', ['note' => 'x'])->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/audit-log')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'payment.mark_refunded')
            ->assertJsonPath('data.0.actor.id', $otherAdmin->id)
            ->assertJsonPath('data.0.target_type', 'payment')
            ->assertJsonPath('data.0.target_id', $payment->id)
            ->assertJsonPath('data.1.action', 'review.grant_attempt');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/audit-log?actor_id='.$this->admin->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor', ['id' => $this->admin->id, 'name' => 'Site Admin', 'email' => 'admin@example.com']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/audit-log?action=review.grant_attempt')
            ->assertJsonCount(1, 'data');
    }

    private function failedReview(bool $readable = false): Review
    {
        $user = User::factory()->create();
        $resume = $readable ? $this->readableResume($user) : Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->verified()->create(['entitlement_consumed_at' => now()]);

        return Review::factory()->for($user)->for($resume)->for($payment)->create([
            'status' => ReviewStatus::Failed,
            'overall_score' => null,
            'completed_at' => null,
            'failure_reason' => 'The resume text could not be extracted for assessment.',
        ]);
    }

    private function readableResume(User $user): Resume
    {
        return Resume::factory()->for($user)->create([
            'extraction_status' => ResumeExtractionStatus::Succeeded,
            'extracted_text' => "Ada Candidate\nada@example.com | +234 801 234 5678\nPROFESSIONAL SUMMARY\nBackend engineer.\n"
                ."WORK EXPERIENCE\nSenior Engineer, Example Ltd | 2021 - 2026\n- Developed services that reduced costs by 20 percent.\n"
                ."EDUCATION\nBSc Computer Science\nSKILLS\nPHP, Laravel, PostgreSQL",
            'extracted_at' => now(),
        ]);
    }
}
