<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_profile_endpoints_return_401_without_token(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/profile', ['name' => 'Updated'])->assertUnauthorized();
    }

    public function test_authenticated_candidate_can_retrieve_profile(): void
    {
        $user = User::factory()->create([
            'phone' => '+2348012345678',
            'location' => 'Lagos, Nigeria',
            'professional_title' => 'Backend Engineer',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/profile');

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.phone', '+2348012345678')
            ->assertJsonPath('data.location', 'Lagos, Nigeria')
            ->assertJsonPath('data.professional_title', 'Backend Engineer');
    }

    public function test_valid_payload_updates_profile_and_returns_200(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/profile', [
            'name' => 'Updated Candidate',
            'email' => 'NEW@EXAMPLE.COM',
            'phone' => '+2348012345678',
            'location' => 'Abuja, Nigeria',
            'professional_title' => 'Product Designer',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Candidate')
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.location', 'Abuja, Nigeria');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Candidate',
            'email' => 'new@example.com',
            'phone' => '+2348012345678',
            'location' => 'Abuja, Nigeria',
            'professional_title' => 'Product Designer',
            'email_verified_at' => null,
        ]);
    }

    public function test_returns_422_when_profile_email_belongs_to_another_candidate(): void
    {
        $user = User::factory()->create(['email' => 'candidate@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/profile', [
            'email' => 'taken@example.com',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'candidate@example.com',
        ]);
    }
}
