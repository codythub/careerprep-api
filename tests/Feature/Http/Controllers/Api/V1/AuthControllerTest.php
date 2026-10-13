<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_payload_registers_candidate_and_returns_201_with_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Candidate',
            'email' => 'ADA@EXAMPLE.COM',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ada Candidate')
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonStructure(['token']);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_returns_422_when_registration_payload_is_invalid(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'existing@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_valid_credentials_return_200_with_token(): void
    {
        User::factory()->create([
            'email' => 'candidate@example.com',
            'password' => 'secure-password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'candidate@example.com',
            'password' => 'secure-password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.email', 'candidate@example.com')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_returns_422_for_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'candidate@example.com',
            'password' => 'secure-password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'candidate@example.com',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_returns_429_after_five_login_attempts_for_an_email_and_ip(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'missing@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_returns_429_after_five_registration_attempts_from_an_ip(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/register', [])->assertTooManyRequests();
    }

    public function test_authenticated_user_endpoint_returns_candidate(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/user');

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_authenticated_user_endpoint_returns_401_without_token(): void
    {
        $this->get('/api/v1/auth/user')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_logout_deletes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $response = $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/logout');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }
}
