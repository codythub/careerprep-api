<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResumeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_resume_endpoints_return_401_without_token(): void
    {
        $this->getJson('/api/v1/resumes')->assertUnauthorized();
        $this->postJson('/api/v1/resumes')->assertUnauthorized();
        $this->getJson('/api/v1/resumes/1')->assertUnauthorized();
        $this->deleteJson('/api/v1/resumes/1')->assertUnauthorized();
    }

    public function test_valid_pdf_is_stored_privately_and_returns_201(): void
    {
        Storage::fake('local');
        config()->set('filesystems.resume_disk', 'local');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('Ada Resume.pdf', "%PDF-1.4\nCareer history");

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/resumes', [
            'resume' => $file,
        ], ['Accept' => 'application/json']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.original_name', 'Ada Resume.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf');

        $resume = Resume::query()->sole();

        $this->assertSame($user->id, $resume->user_id);
        $this->assertSame('local', $resume->disk);
        $this->assertStringStartsWith('users/'.$user->id.'/', $resume->path);
        Storage::disk('local')->assertExists($resume->path);
        $response->assertJsonMissingPath('data.path');
    }

    public function test_returns_422_for_non_pdf_upload(): void
    {
        Storage::fake('resumes');
        config()->set('filesystems.resume_disk', 'resumes');
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/resumes', [
            'resume' => UploadedFile::fake()->createWithContent('resume.txt', 'Not a PDF'),
        ], ['Accept' => 'application/json']);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resume']);

        $this->assertDatabaseCount('resumes', 0);
    }

    public function test_returns_422_when_resume_file_is_missing(): void
    {
        Storage::fake('resumes');
        config()->set('filesystems.resume_disk', 'resumes');
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/resumes');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resume']);

        $this->assertDatabaseCount('resumes', 0);
    }

    public function test_returns_422_for_pdf_larger_than_five_megabytes(): void
    {
        Storage::fake('resumes');
        config()->set('filesystems.resume_disk', 'resumes');
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/resumes', [
            'resume' => UploadedFile::fake()->create('resume.pdf', 5121, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resume']);

        $this->assertDatabaseCount('resumes', 0);
    }

    public function test_list_returns_only_authenticated_candidates_resumes(): void
    {
        $user = User::factory()->create();
        $ownResume = Resume::factory()->for($user)->create();
        Resume::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/resumes');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownResume->id);
    }

    public function test_candidate_can_retrieve_own_resume_metadata(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create([
            'original_name' => 'career-resume.pdf',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/resumes/'.$resume->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $resume->id)
            ->assertJsonPath('data.original_name', 'career-resume.pdf')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');
    }

    public function test_returns_404_when_candidate_retrieves_another_candidates_resume(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/resumes/'.$resume->id)
            ->assertNotFound();
    }

    public function test_candidate_can_delete_own_resume_and_private_file(): void
    {
        Storage::fake('resumes');
        $user = User::factory()->create();
        Storage::disk('resumes')->put('users/1/resume.pdf', 'PDF content');
        $resume = Resume::factory()->for($user)->create([
            'disk' => 'resumes',
            'path' => 'users/1/resume.pdf',
        ]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/resumes/'.$resume->id);

        $response->assertNoContent();

        $this->assertModelMissing($resume);
        Storage::disk('resumes')->assertMissing('users/1/resume.pdf');
    }

    public function test_returns_404_when_candidate_deletes_another_candidates_resume(): void
    {
        Storage::fake('resumes');
        $user = User::factory()->create();
        $resume = Resume::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/resumes/'.$resume->id)
            ->assertNotFound();

        $this->assertModelExists($resume);
    }

    public function test_returns_409_when_deleting_resume_linked_to_payment(): void
    {
        Storage::fake('resumes');
        $user = User::factory()->create();
        Storage::disk('resumes')->put('users/1/paid-resume.pdf', 'PDF content');
        $resume = Resume::factory()->for($user)->create([
            'disk' => 'resumes',
            'path' => 'users/1/paid-resume.pdf',
        ]);
        Payment::factory()->for($user)->for($resume)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/resumes/'.$resume->id)
            ->assertConflict();

        $this->assertModelExists($resume);
        Storage::disk('resumes')->assertExists('users/1/paid-resume.pdf');
    }
}
