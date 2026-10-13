<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Models\Resume;
use App\Models\User;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaystackWebhookControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.paystack.secret_key', 'webhook-secret');
    }

    public function test_valid_charge_success_webhook_verifies_payment(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->create([
            'reference' => 'CP-WEBHOOK-1',
            'amount' => 500_000,
            'currency' => 'NGN',
        ]);
        $payload = $this->chargeSuccessPayload($payment);

        $response = $this->postSignedWebhook($payload);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Webhook acknowledged.');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Verified->value,
            'provider_transaction_id' => '7654321',
        ]);
    }

    public function test_retried_webhook_is_idempotent(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->create([
            'reference' => 'CP-WEBHOOK-IDEMPOTENT',
            'amount' => 500_000,
            'currency' => 'NGN',
        ]);
        $payload = $this->chargeSuccessPayload($payment);

        $this->postSignedWebhook($payload)->assertOk();
        $firstVerifiedAt = $payment->refresh()->verified_at?->toISOString();
        $this->travel(5)->minutes();
        $this->postSignedWebhook($payload)->assertOk();

        $this->assertSame($firstVerifiedAt, $payment->refresh()->verified_at?->toISOString());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_returns_401_for_invalid_webhook_signature(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $payment = Payment::factory()->for($user)->for($resume)->create();
        $payload = $this->chargeSuccessPayload($payment);

        $response = $this->call(
            'POST',
            '/api/v1/paystack/webhook',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'invalid'],
            content: $payload,
        );

        $response->assertUnauthorized();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Initialized->value,
        ]);
    }

    private function chargeSuccessPayload(Payment $payment): string
    {
        return (string) json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => 7_654_321,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function postSignedWebhook(string $payload): TestResponse
    {
        $signature = hash_hmac('sha512', $payload, 'webhook-secret');

        return $this->call(
            'POST',
            '/api/v1/paystack/webhook',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature],
            content: $payload,
        );
    }
}
