<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TokenExpiryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_login_returns_token_expiry_and_token_stops_working_after_it(): void
    {
        config()->set('sanctum.expiration', 60);
        $this->freezeSecond();
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'password123']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password123']);

        $response
            ->assertOk()
            ->assertJsonPath('expires_at', now()->addMinutes(60)->toISOString());

        $token = $response->json('token');

        $this->travel(59)->minutes();
        $this->withToken($token)->getJson('/api/v1/auth/user')->assertOk();

        $this->travel(2)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/auth/user')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_registration_returns_token_expiry(): void
    {
        config()->set('sanctum.expiration', 10080);
        $this->freezeSecond();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Candidate',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertCreated()
            ->assertJsonPath('expires_at', now()->addMinutes(10080)->toISOString());

        $this->assertNotNull(User::query()->sole()->tokens()->sole()->expires_at);
    }

    public function test_token_issued_before_expiry_was_configured_expires_by_its_age(): void
    {
        $user = User::factory()->create();
        $legacyToken = $user->createToken('careerprep-web')->plainTextToken;
        $user->tokens()->update(['created_at' => now()->subDays(8)]);
        config()->set('sanctum.expiration', 10080);

        $this->withToken($legacyToken)->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_expiry_can_be_disabled(): void
    {
        config()->set('sanctum.expiration', null);
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'password123']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('expires_at', null);

        $this->travel(365)->days();

        $this->withToken($response->json('token'))->getJson('/api/v1/auth/user')->assertOk();
    }
}
