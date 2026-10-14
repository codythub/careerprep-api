<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Admin;

use App\Models\Payment;
use App\Models\Resume;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSeparationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function candidateRoutes(): array
    {
        return [
            'resumes.index' => ['GET', '/api/v1/resumes'],
            'resumes.store' => ['POST', '/api/v1/resumes'],
            'resumes.show' => ['GET', '/api/v1/resumes/{resume}'],
            'resumes.destroy' => ['DELETE', '/api/v1/resumes/{resume}'],
            'payments.index' => ['GET', '/api/v1/payments'],
            'payments.store' => ['POST', '/api/v1/payments'],
            'payments.show' => ['GET', '/api/v1/payments/{payment}'],
            'payments.verify' => ['POST', '/api/v1/payments/{payment}/verify'],
            'reviews.index' => ['GET', '/api/v1/reviews'],
            'reviews.store' => ['POST', '/api/v1/reviews'],
            'reviews.show' => ['GET', '/api/v1/reviews/{review}'],
            'reviews.retry' => ['POST', '/api/v1/reviews/{review}/retry'],
            'reviews.download' => ['GET', '/api/v1/reviews/{review}/download'],
        ];
    }

    #[DataProvider('candidateRoutes')]
    public function test_admin_token_gets_403_on_every_candidate_route(string $method, string $uri): void
    {
        Http::preventStrayRequests();
        Storage::fake('resumes');
        $admin = User::factory()->admin()->create();
        $review = Review::factory()->create();
        $payload = match (true) {
            $uri === '/api/v1/resumes' && $method === 'POST' => ['resume' => UploadedFile::fake()->createWithContent('cv.pdf', '%PDF-1.4')],
            $uri === '/api/v1/payments' && $method === 'POST' => ['resume_id' => $review->resume_id],
            $uri === '/api/v1/reviews' && $method === 'POST' => ['payment_id' => $review->payment_id],
            default => [],
        };

        $this->withToken($admin->createToken('careerprep-web')->plainTextToken)
            ->json($method, strtr($uri, [
                '{resume}' => $review->resume_id,
                '{payment}' => $review->payment_id,
                '{review}' => $review->id,
            ]), $payload)
            ->assertForbidden()
            ->assertExactJson(['message' => 'This action is unauthorized.']);

        $this->assertDatabaseCount('resumes', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('reviews', 1);
    }

    #[DataProvider('candidateRoutes')]
    public function test_admin_gets_403_not_404_for_ids_that_do_not_exist(string $method, string $uri): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->json($method, strtr($uri, ['{resume}' => 999_999, '{payment}' => 999_999, '{review}' => 999_999]))
            ->assertForbidden();
    }

    public function test_candidates_can_still_use_candidate_routes(): void
    {
        $review = Review::factory()->create();

        $this->actingAs($review->user, 'sanctum')->getJson('/api/v1/resumes')->assertOk();
        $this->actingAs($review->user, 'sanctum')->getJson('/api/v1/payments/'.$review->payment_id)->assertOk();
        $this->actingAs($review->user, 'sanctum')->getJson('/api/v1/reviews/'.$review->id)->assertOk();
    }

    public function test_both_roles_can_use_auth_profile_and_pricing(): void
    {
        foreach ([User::factory()->create(['password' => 'password123']), User::factory()->admin()->create(['password' => 'password123'])] as $user) {
            $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123'])
                ->assertOk()
                ->json('token');

            $this->withToken($token)->getJson('/api/v1/auth/user')->assertOk()->assertJsonPath('data.id', $user->id);
            $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
            $this->withToken($token)
                ->patchJson('/api/v1/profile', ['name' => 'Renamed '.$user->role->value, 'location' => 'Lagos'])
                ->assertOk()
                ->assertJsonPath('data.name', 'Renamed '.$user->role->value)
                ->assertJsonPath('data.role', $user->role->value);
            $this->withToken($token)->getJson('/api/v1/pricing')->assertOk();
            $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_candidate_data_can_never_be_created_for_an_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->create();
        $resume = Resume::factory()->for($candidate)->create();

        foreach ([
            fn () => Resume::factory()->for($admin)->create(),
            fn () => Payment::factory()->for($admin)->for($resume)->create(),
            fn () => Review::factory()->for($admin)->for($resume)->create(),
            fn () => $resume->forceFill(['user_id' => $admin->id])->save(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Candidate data was attached to an admin account.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('cannot belong to an admin account', $exception->getMessage());
            }
        }

        $this->assertSame(0, $admin->resumes()->count() + $admin->payments()->count() + $admin->reviews()->count());
        $this->assertSame($candidate->id, $resume->refresh()->user_id);
    }
}
