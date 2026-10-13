<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PricingControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.paystack.secret_key' => 'paystack-test-secret',
            'services.paystack.base_url' => 'https://api.paystack.co',
            'services.paystack.review_amount' => 500_000,
            'services.paystack.currency' => 'ngn',
        ]);
    }

    public function test_guest_can_read_the_configured_review_price(): void
    {
        $response = $this->getJson('/api/v1/pricing');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'review' => ['amount' => 500_000, 'currency' => 'NGN', 'available' => true],
                ],
            ]);

        $this->assertStringNotContainsString('paystack-test-secret', $response->getContent());
    }

    public function test_price_is_reported_unavailable_when_payments_are_not_configured(): void
    {
        config()->set('services.paystack.secret_key', null);

        $this->getJson('/api/v1/pricing')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'review' => ['amount' => null, 'currency' => null, 'available' => false],
                ],
            ]);
    }

    public function test_checkout_charges_the_published_price_and_ignores_client_amounts(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.paystack.co/transaction/initialize' => fn (Request $request) => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/access-code',
                    'access_code' => 'access-code',
                    'reference' => $request['reference'],
                ],
            ]),
        ]);
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $published = $this->getJson('/api/v1/pricing')->json('data.review');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments', ['resume_id' => $resume->id, 'amount' => 100, 'currency' => 'USD'])
            ->assertCreated()
            ->assertJsonPath('data.amount', $published['amount'])
            ->assertJsonPath('data.currency', $published['currency']);

        $this->assertSame($published['amount'], Payment::query()->sole()->amount);
        Http::assertSent(fn (Request $request): bool => $request['amount'] === '500000' && $request['currency'] === 'NGN');
    }
}
