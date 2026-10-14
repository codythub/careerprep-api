<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Admin;

use App\Models\AdminAuditLog;
use App\Models\Payment;
use App\Models\Review;
use App\Models\User;
use App\PaymentStatus;
use App\ReviewStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminPaymentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.paystack.secret_key' => 'paystack-test-secret',
            'services.paystack.base_url' => 'https://api.paystack.co',
        ]);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_payments_can_be_filtered_and_never_expose_provider_internals(): void
    {
        $user = User::factory()->create();
        $verified = Payment::factory()->for($user)->verified()->create([
            'reference' => 'CP-FIND-ME',
            'access_code' => 'secret-access-code',
            'provider_payload' => ['authorization' => ['authorization_code' => 'AUTH_secret']],
        ]);
        Payment::factory()->create(['created_at' => now()->subDays(10)]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/payments?status=verified');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $verified->id)
            ->assertJsonPath('data.0.user.email', $user->email)
            ->assertJsonPath('data.0.entitlement_status', 'available')
            ->assertJsonPath('data.0.review_id', null)
            ->assertJsonPath('meta.per_page', 15);

        foreach (['secret-access-code', 'AUTH_secret', 'provider_payload', 'access_code', 'authorization_url'] as $needle) {
            $this->assertStringNotContainsString($needle, $response->getContent());
        }

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/payments?reference=CP-FIND-ME')->assertJsonCount(1, 'data');
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/payments?user_id='.$user->id)->assertJsonCount(1, 'data');
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/payments?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())
            ->assertJsonCount(1, 'data');
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/payments?from=2026-10-10&to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_payment_detail_includes_user_and_linked_review(): void
    {
        $review = Review::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/payments/'.$review->payment_id)
            ->assertOk()
            ->assertJsonPath('data.review_id', $review->id)
            ->assertJsonPath('data.user.id', $review->user_id)
            ->assertJsonPath('data.entitlement_status', 'available');
    }

    public function test_reverify_marks_a_paid_transaction_verified_and_is_audited(): void
    {
        $payment = Payment::factory()->create(['reference' => 'CP-REVERIFY', 'amount' => 600_000, 'currency' => 'NGN']);
        $this->fakePaystack('CP-REVERIFY', ['status' => 'success', 'amount' => 600_000, 'currency' => 'NGN']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/'.$payment->id.'/reverify')
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.entitlement_status', 'available')
            ->assertJsonPath('meta.verified', true)
            ->assertJsonPath('meta.provider_status', 'success');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/'.$payment->id.'/reverify')
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $log = AdminAuditLog::query()->where('action', 'payment.reverify')->firstOrFail();
        $this->assertSame(['status' => 'initialized'], $log->before);
        $this->assertSame('verified', $log->after['status']);
        $this->assertSame(2, AdminAuditLog::query()->count());
    }

    public function test_reverify_does_not_verify_a_mismatched_or_unpaid_transaction(): void
    {
        $payment = Payment::factory()->create(['reference' => 'CP-SHORT', 'amount' => 600_000, 'currency' => 'NGN']);
        $this->fakePaystack('CP-SHORT', ['status' => 'success', 'amount' => 100, 'currency' => 'NGN']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/'.$payment->id.'/reverify')
            ->assertOk()
            ->assertJsonPath('data.status', 'initialized')
            ->assertJsonPath('meta.verified', false);

        $this->assertSame(PaymentStatus::Initialized, $payment->refresh()->status);
    }

    public function test_reverify_returns_502_when_paystack_fails(): void
    {
        $payment = Payment::factory()->create();
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false], 500)]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/'.$payment->id.'/reverify')
            ->assertStatus(502);

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_mark_refunded_makes_an_unused_entitlement_unavailable(): void
    {
        $payment = Payment::factory()->verified()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/'.$payment->id.'/mark-refunded', ['note' => '  Refunded in Paystack, ticket #42 '])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.entitlement_status', 'unavailable')
            ->assertJsonPath('data.refund_note', 'Refunded in Paystack, ticket #42');

        $payment->refresh();
        $this->assertNotNull($payment->refunded_at);

        $log = AdminAuditLog::query()->sole();
        $this->assertSame('payment.mark_refunded', $log->action);
        $this->assertSame(['status' => 'verified', 'entitlement_status' => 'available'], $log->before);
        $this->assertSame('Refunded in Paystack, ticket #42', $log->after['note']);

        $this->actingAs($payment->user, 'sanctum')
            ->postJson('/api/v1/reviews', ['payment_id' => $payment->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_id' => 'refunded']);
    }

    public function test_paystack_webhook_and_candidate_verify_never_undo_a_refund(): void
    {
        $payment = Payment::factory()->verified()->create(['reference' => 'CP-REFUNDED', 'amount' => 600_000, 'currency' => 'NGN']);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/'.$payment->id.'/mark-refunded', ['note' => 'Refunded'])
            ->assertOk();

        $body = json_encode(['event' => 'charge.success', 'data' => [
            'status' => 'success', 'reference' => 'CP-REFUNDED', 'amount' => 600_000, 'currency' => 'NGN',
        ]]);
        $this->call('POST', '/api/v1/paystack/webhook', server: [
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'paystack-test-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], content: $body)->assertOk();

        $this->fakePaystack('CP-REFUNDED', ['status' => 'success', 'amount' => 600_000, 'currency' => 'NGN']);
        $this->actingAs($payment->user, 'sanctum')->postJson('/api/v1/payments/'.$payment->id.'/verify');

        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/'.$payment->id.'/reverify')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);
    }

    public function test_refunding_a_used_entitlement_blocks_candidate_retries(): void
    {
        $review = Review::factory()->create(['status' => ReviewStatus::Failed]);
        $review->payment->update(['entitlement_consumed_at' => now()]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/'.$review->payment_id.'/mark-refunded', ['note' => 'Refunded after failed review'])
            ->assertOk()
            ->assertJsonPath('data.entitlement_status', 'consumed')
            ->assertJsonPath('data.review_id', $review->id);

        $this->actingAs($review->user, 'sanctum')
            ->getJson('/api/v1/reviews/'.$review->id)
            ->assertJsonPath('data.can_retry', false);

        $this->actingAs($review->user, 'sanctum')
            ->postJson('/api/v1/reviews/'.$review->id.'/retry')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review' => 'refunded']);
    }

    public function test_mark_refunded_validation_and_state_rules(): void
    {
        $initialized = Payment::factory()->create();
        $verified = Payment::factory()->verified()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/'.$verified->id.'/mark-refunded', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/'.$initialized->id.'/mark-refunded', ['note' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment' => 'Only verified payments']);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/payments/'.$verified->id.'/mark-refunded', ['note' => 'x'])->assertOk();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/'.$verified->id.'/mark-refunded', ['note' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment' => 'already marked']);

        $this->assertSame(1, AdminAuditLog::query()->count());
    }

    /** @param array<string, mixed> $data */
    private function fakePaystack(string $reference, array $data): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/'.$reference => Http::response([
                'status' => true,
                'data' => ['id' => 123456, 'reference' => $reference, ...$data],
            ]),
        ]);
    }
}
