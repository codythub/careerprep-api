<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Models\Resume;
use App\Models\Review;
use App\Models\User;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.paystack.secret_key' => 'paystack-test-secret',
            'services.paystack.base_url' => 'https://api.paystack.co',
            'services.paystack.review_amount' => 500_000,
            'services.paystack.currency' => 'NGN',
            'services.paystack.callback_url' => 'https://frontend.example/reviews',
        ]);
    }

    public function test_payment_endpoints_return_401_without_token(): void
    {
        $this->postJson('/api/v1/payments')->assertUnauthorized();
        $this->postJson('/api/v1/payments/1/verify')->assertUnauthorized();
        $this->getJson('/api/v1/payments')->assertUnauthorized();
        $this->getJson('/api/v1/payments/1')->assertUnauthorized();
    }

    public function test_index_lists_only_the_candidates_payments_with_entitlement_state(): void
    {
        $user = User::factory()->create();
        $available = Payment::factory()->for($user)->verified()->create();
        $consumed = Payment::factory()->for($user)->verified()->create(['entitlement_consumed_at' => now()]);
        $review = Review::factory()->for($user)->create(['resume_id' => $consumed->resume_id, 'payment_id' => $consumed->id]);
        Payment::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/payments');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 15);

        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertSame('available', $rows[$available->id]['entitlement_status']);
        $this->assertNull($rows[$available->id]['review_id']);
        $this->assertSame('consumed', $rows[$consumed->id]['entitlement_status']);
        $this->assertSame($review->id, $rows[$consumed->id]['review_id']);
    }

    public function test_index_filters_by_reference_within_the_candidates_payments(): void
    {
        $user = User::factory()->create();
        $payment = Payment::factory()->for($user)->create(['reference' => 'CP-CALLBACK-1']);
        Payment::factory()->for($user)->create();
        Payment::factory()->create(['reference' => 'CP-OTHER-USER']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/payments?reference=CP-CALLBACK-1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $payment->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/payments?reference=CP-OTHER-USER')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_show_returns_own_payment_and_404_for_another_candidates(): void
    {
        $user = User::factory()->create();
        $payment = Payment::factory()->for($user)->create();
        $otherPayment = Payment::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/payments/'.$payment->id)
            ->assertOk()
            ->assertJsonPath('data.id', $payment->id)
            ->assertJsonPath('data.reference', $payment->reference)
            ->assertJsonPath('data.status', 'initialized')
            ->assertJsonPath('data.entitlement_status', 'unavailable')
            ->assertJsonPath('data.review_id', null);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/payments/'.$otherPayment->id)
            ->assertNotFound();
    }

    public function test_valid_request_initializes_transaction_and_returns_201(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.paystack.co/transaction/initialize' => function (Request $request) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/access-code',
                        'access_code' => 'access-code',
                        'reference' => $request['reference'],
                    ],
                ]);
            },
        ]);
        $user = User::factory()->create(['email' => 'candidate@example.com']);
        $resume = Resume::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/payments', [
            'resume_id' => $resume->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.resume_id', $resume->id)
            ->assertJsonPath('data.amount', 500_000)
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.status', 'initialized')
            ->assertJsonPath('data.entitlement_status', 'unavailable')
            ->assertJsonPath('data.access_code', 'access-code');

        $payment = Payment::query()->sole();

        $this->assertSame($user->id, $payment->user_id);
        $this->assertSame($resume->id, $payment->resume_id);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request->hasHeader('Authorization', 'Bearer paystack-test-secret')
            && $request['email'] === 'candidate@example.com'
            && $request['amount'] === '500000'
            && $request['callback_url'] === 'https://frontend.example/reviews');
    }

    public function test_returns_404_when_initializing_payment_for_another_candidates_resume(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $resume = Resume::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments', ['resume_id' => $resume->id])
            ->assertNotFound();

        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_server_verification_marks_payment_verified_and_provides_entitlement(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->create([
            'reference' => 'CP-VERIFY-1',
            'amount' => 500_000,
            'currency' => 'NGN',
        ]);
        Http::fake([
            'api.paystack.co/transaction/verify/CP-VERIFY-1' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 4_099_260_516,
                    'status' => 'success',
                    'reference' => 'CP-VERIFY-1',
                    'amount' => 500_000,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments/'.$payment->id.'/verify');

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.entitlement_status', 'available');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Verified->value,
            'provider_transaction_id' => '4099260516',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.paystack.co/transaction/verify/CP-VERIFY-1');
    }

    public function test_returns_422_when_verified_amount_does_not_match(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->create([
            'reference' => 'CP-WRONG-AMOUNT',
            'amount' => 500_000,
            'currency' => 'NGN',
        ]);
        Http::fake([
            'api.paystack.co/transaction/verify/CP-WRONG-AMOUNT' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'CP-WRONG-AMOUNT',
                    'amount' => 100,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments/'.$payment->id.'/verify');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Initialized->value,
            'verified_at' => null,
        ]);
    }
}
