<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Admin;

use App\Models\AdminAuditLog;
use App\Models\Payment;
use App\Models\Resume;
use App\Models\Review;
use App\Models\User;
use App\ResumeExtractionStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminUserAndResumeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Site Admin', 'email' => 'admin@example.com']);
    }

    public function test_users_can_be_searched_and_filtered_with_counts(): void
    {
        $ada = User::factory()->create(['name' => 'Ada Okafor', 'email' => 'ada@example.com']);
        User::factory()->create(['name' => 'Bola Ade', 'email' => 'bola@example.com']);
        Review::factory()->for($ada)->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?search=OKAF')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ada->id)
            ->assertJsonPath('data.0.role', 'candidate')
            ->assertJsonPath('data.0.resumes_count', 1)
            ->assertJsonPath('data.0.payments_count', 1)
            ->assertJsonPath('data.0.reviews_count', 1);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?search=bola@')
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?role=admin')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->admin->id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?role=owner')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $underscored = User::factory()->create(['name' => 'Ada', 'email' => 'ada_x@example.com']);
        User::factory()->create(['name' => 'Bola', 'email' => 'adayx@example.com']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?search=ada_x')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $underscored->id);
    }

    public function test_user_detail_includes_counts_and_latest_activity_without_secrets(): void
    {
        $user = User::factory()->create();
        $payment = Payment::factory()->for($user)->verified()->create([
            'access_code' => 'secret-access-code',
            'authorization_url' => 'https://checkout.paystack.com/secret-checkout',
            'provider_payload' => ['authorization' => ['authorization_code' => 'AUTH_secret']],
        ]);
        Review::factory()->for($user)->for($payment->resume)->for($payment)->create();

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/users/'.$user->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.payments_count', 1)
            ->assertJsonCount(1, 'data.latest_resumes')
            ->assertJsonCount(1, 'data.latest_payments')
            ->assertJsonCount(1, 'data.latest_reviews')
            ->assertJsonPath('data.latest_payments.0.review_id', $payment->review->id);

        $this->assertNoSecrets($response->getContent(), $payment->resume);
    }

    public function test_resumes_list_filters_and_never_exposes_storage_details(): void
    {
        $user = User::factory()->create();
        $failed = Resume::factory()->for($user)->create([
            'extraction_status' => ResumeExtractionStatus::Failed,
            'extracted_text' => null,
        ]);
        $succeeded = Resume::factory()->create([
            'extraction_status' => ResumeExtractionStatus::Succeeded,
            'extracted_text' => 'Private resume text',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/resumes?extraction_status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $failed->id)
            ->assertJsonPath('data.0.user.email', $user->email);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/resumes?user_id='.$user->id)
            ->assertJsonCount(1, 'data');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/resumes/'.$succeeded->id)
            ->assertOk()
            ->assertJsonPath('data.extraction_status', 'succeeded')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk')
            ->assertJsonMissingPath('data.extracted_text');

        $this->assertNoSecrets($response->getContent(), $succeeded);
        $this->assertStringNotContainsString('Private resume text', $response->getContent());
    }

    public function test_admin_can_download_a_resume_pdf_and_it_is_audited(): void
    {
        Storage::fake('resumes');
        $resume = Resume::factory()->create(['original_name' => 'Ada CV.pdf']);
        Storage::disk('resumes')->put($resume->path, '%PDF-1.4 resume-bytes');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/v1/admin/resumes/'.$resume->id.'/download');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename="Ada CV.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 resume-bytes', $response->streamedContent());

        $log = AdminAuditLog::query()->sole();
        $this->assertSame('resume.download', $log->action);
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame('resume', $log->target_type);
        $this->assertSame($resume->id, $log->target_id);
        $this->assertNotNull($log->ip_address);
        $this->assertStringNotContainsString($resume->path, json_encode($log->after));
    }

    public function test_download_returns_404_when_the_file_is_missing(): void
    {
        Storage::fake('resumes');
        $resume = Resume::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/resumes/'.$resume->id.'/download')
            ->assertNotFound()
            ->assertJsonPath('message', 'The resume file is no longer available.');

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    private function assertNoSecrets(string $json, Resume $resume): void
    {
        foreach (['secret-access-code', 'secret-checkout', 'AUTH_secret', 'provider_payload', 'access_code', 'authorization_url', $resume->path, '"disk"'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }
}
