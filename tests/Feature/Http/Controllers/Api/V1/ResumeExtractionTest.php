<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Resume;
use App\Models\User;
use App\ResumeExtractionStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResumeExtractionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_upload_extracts_text_and_persists_succeeded_status(): void
    {
        Storage::fake('resumes');
        config()->set('filesystems.resume_disk', 'resumes');
        $user = User::factory()->create();
        $pdf = UploadedFile::fake()->createWithContent('resume.pdf', $this->readablePdf());

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/resumes', [
            'resume' => $pdf,
        ], ['Accept' => 'application/json']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.extraction_status', 'succeeded')
            ->assertJsonPath('data.extraction_error', null);

        $resume = Resume::query()->sole();

        $this->assertSame(ResumeExtractionStatus::Succeeded, $resume->extraction_status);
        $this->assertStringContainsString('John Candidate', $resume->extracted_text);
        $this->assertStringContainsString('WORK EXPERIENCE', $resume->extracted_text);
        $this->assertNotNull($resume->extracted_at);
    }

    public function test_upload_persists_failed_status_when_pdf_text_cannot_be_extracted(): void
    {
        Storage::fake('resumes');
        config()->set('filesystems.resume_disk', 'resumes');
        $user = User::factory()->create();
        $pdf = UploadedFile::fake()->createWithContent(
            'encrypted.pdf',
            "%PDF-1.4\ntrailer\n<< /Encrypt 1 0 R >>\n%%EOF",
        );

        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/resumes', [
            'resume' => $pdf,
        ], ['Accept' => 'application/json']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.extraction_status', 'failed')
            ->assertJsonPath('data.extraction_error', 'Text could not be extracted from this PDF.');

        $resume = Resume::query()->sole();

        $this->assertSame(ResumeExtractionStatus::Failed, $resume->extraction_status);
        $this->assertNull($resume->extracted_text);
        $this->assertNull($resume->extracted_at);
    }

    private function readablePdf(): string
    {
        return <<<'PDF'
%PDF-1.4
1 0 obj
<< /Length 200 >>
stream
BT
(John Candidate) Tj
(john@example.com) Tj
(PROFESSIONAL SUMMARY) Tj
(Backend engineer focused on reliable applications.) Tj
(WORK EXPERIENCE) Tj
(Developed secure web services from 2020 to 2026.) Tj
(EDUCATION) Tj
(Bachelor of Science, Example University) Tj
(SKILLS) Tj
(PHP, Laravel, PostgreSQL, Docker, AWS) Tj
ET
endstream
endobj
trailer
<< >>
%%EOF
PDF;
    }
}
