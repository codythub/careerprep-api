<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Admin;

use App\Models\Payment;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function adminRoutes(): array
    {
        return [
            'stats' => ['GET', '/api/v1/admin/stats'],
            'users.index' => ['GET', '/api/v1/admin/users'],
            'users.show' => ['GET', '/api/v1/admin/users/{user}'],
            'resumes.index' => ['GET', '/api/v1/admin/resumes'],
            'resumes.show' => ['GET', '/api/v1/admin/resumes/{resume}'],
            'resumes.download' => ['GET', '/api/v1/admin/resumes/{resume}/download'],
            'payments.index' => ['GET', '/api/v1/admin/payments'],
            'payments.show' => ['GET', '/api/v1/admin/payments/{payment}'],
            'payments.reverify' => ['POST', '/api/v1/admin/payments/{payment}/reverify'],
            'payments.mark-refunded' => ['POST', '/api/v1/admin/payments/{payment}/mark-refunded'],
            'reviews.index' => ['GET', '/api/v1/admin/reviews'],
            'reviews.show' => ['GET', '/api/v1/admin/reviews/{review}'],
            'reviews.retry' => ['POST', '/api/v1/admin/reviews/{review}/retry'],
            'reviews.grant-attempt' => ['POST', '/api/v1/admin/reviews/{review}/grant-attempt'],
            'audit-log' => ['GET', '/api/v1/admin/audit-log'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_admin_route_returns_401_without_a_token(string $method, string $uri): void
    {
        $this->json($method, $this->withIds($uri, 1))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[DataProvider('adminRoutes')]
    public function test_admin_route_returns_403_for_a_candidate_token(string $method, string $uri): void
    {
        $candidate = User::factory()->create();
        $token = $candidate->createToken('careerprep-web')->plainTextToken;
        $review = Review::factory()->for($candidate)->create();

        $this->withToken($token)
            ->json($method, $this->withIds($uri, $review->id, $review->payment_id, $review->resume_id, $candidate->id))
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    #[DataProvider('adminRoutes')]
    public function test_candidate_gets_403_not_404_for_ids_that_do_not_exist(string $method, string $uri): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->json($method, $this->withIds($uri, 999_999))
            ->assertForbidden();
    }

    #[DataProvider('adminRoutes')]
    public function test_admin_token_can_reach_the_route(string $method, string $uri): void
    {
        Storage::fake('resumes');
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['status' => 'abandoned']])]);
        config()->set('services.paystack.secret_key', 'paystack-test-secret');
        $admin = User::factory()->admin()->create();
        $payment = Payment::factory()->verified()->create();
        $review = Review::factory()->for($payment->user)->for($payment->resume)->for($payment)->create(['status' => 'failed']);
        Storage::disk('resumes')->put($payment->resume->path, '%PDF-1.4 test');
        $payload = $method === 'POST' && str_contains($uri, 'mark-refunded') ? ['note' => 'Refunded in Paystack dashboard'] : [];

        $response = $this->withToken($admin->createToken('careerprep-web')->plainTextToken)
            ->json($method, $this->withIds($uri, $review->id, $payment->id, $payment->resume_id, $payment->user_id), $payload);

        $this->assertSame(200, $response->baseResponse->getStatusCode());
    }

    private function withIds(string $uri, int $review, ?int $payment = null, ?int $resume = null, ?int $user = null): string
    {
        return strtr($uri, [
            '{review}' => $review,
            '{payment}' => $payment ?? $review,
            '{resume}' => $resume ?? $review,
            '{user}' => $user ?? $review,
        ]);
    }
}
