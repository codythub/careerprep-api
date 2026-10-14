<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Contracts\ResumeTextExtractor;
use App\Models\Resume;
use App\Models\User;
use App\ResumeExtractionStatus;
use App\Services\ExtractResumeText;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class ResumeExtractionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_upload_extracts_text_and_persists_succeeded_status(): void
    {
        Storage::fake('resumes');
        config()->set('filesystems.resume_disk', 'resumes');
        $user = User::factory()->create();

        $response = $this->upload($user, PdfFixture::simple($this->resumeLines()));

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

    public function test_google_docs_style_pdf_with_identity_h_fonts_is_extracted_as_real_text(): void
    {
        Storage::fake('resumes');
        config()->set('filesystems.resume_disk', 'resumes');
        $user = User::factory()->create();

        $this->upload($user, PdfFixture::identityH($this->resumeLines()))
            ->assertCreated()
            ->assertJsonPath('data.extraction_status', 'succeeded');

        $text = Resume::query()->sole()->extracted_text;

        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertStringContainsString('John Candidate', $text);
        $this->assertStringContainsString('john@example.com', $text);
        $this->assertStringContainsString('Senior Backend Engineer, Example Ltd (2021 – 2026)', $text);
        $this->assertStringContainsString('EDUCATION', $text);
    }

    public function test_upload_persists_failed_status_when_pdf_cannot_be_parsed(): void
    {
        Storage::fake('resumes');
        config()->set('filesystems.resume_disk', 'resumes');
        $user = User::factory()->create();

        $this->upload($user, "%PDF-1.4\ntrailer\n<< /Encrypt 1 0 R >>\n%%EOF")
            ->assertCreated()
            ->assertJsonPath('data.extraction_status', 'failed')
            ->assertJsonPath('data.extraction_error', 'Text could not be extracted from this PDF.');

        $resume = Resume::query()->sole();

        $this->assertSame(ResumeExtractionStatus::Failed, $resume->extraction_status);
        $this->assertNull($resume->extracted_text);
        $this->assertNull($resume->extracted_at);
    }

    public function test_pdf_with_too_little_readable_text_is_marked_failed_instead_of_assessed(): void
    {
        Storage::fake('resumes');
        config()->set('filesystems.resume_disk', 'resumes');
        $user = User::factory()->create();

        $this->upload($user, PdfFixture::simple(['Scanned page 1', '#### ---- ####']))
            ->assertCreated()
            ->assertJsonPath('data.extraction_status', 'failed')
            ->assertJsonPath('data.extraction_error', 'Not enough readable text was found in this PDF. Upload a text-based PDF, not a scanned image.');

        $this->assertNull(Resume::query()->sole()->extracted_text);
    }

    public function test_text_made_mostly_of_symbols_is_marked_failed(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $this->fakeExtractor(str_repeat('ab ', 30).str_repeat('#%&*!?0123456789 ', 20));

        $resume = app(ExtractResumeText::class)->handle($resume);

        $this->assertSame(ResumeExtractionStatus::Failed, $resume->extraction_status);
    }

    public function test_invalid_utf8_bytes_are_removed_before_text_is_stored(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $this->fakeExtractor(implode("\n", $this->resumeLines())."\x8d\x00 trailing\xC3 bytes");

        $resume = app(ExtractResumeText::class)->handle($resume);

        $this->assertSame(ResumeExtractionStatus::Succeeded, $resume->extraction_status);
        $this->assertTrue(mb_check_encoding($resume->extracted_text, 'UTF-8'));
        $this->assertStringNotContainsString("\x00", $resume->extracted_text);
        $this->assertStringContainsString('John Candidate', $resume->extracted_text);
    }

    private function fakeExtractor(string $text): void
    {
        $this->app->instance(ResumeTextExtractor::class, new class($text) implements ResumeTextExtractor
        {
            public function __construct(private string $text) {}

            public function extract(Resume $resume): string
            {
                return $this->text;
            }
        });
    }

    /** @return list<string> */
    private function resumeLines(): array
    {
        return [
            'John Candidate',
            'john@example.com | +234 801 234 5678',
            'PROFESSIONAL SUMMARY',
            'Backend engineer focused on reliable applications and measurable delivery outcomes.',
            'WORK EXPERIENCE',
            'Senior Backend Engineer, Example Ltd (2021 – 2026)',
            'Developed secure web services that reduced response times by 35 percent.',
            'Led a team of four engineers delivering payment integrations.',
            'EDUCATION',
            'Bachelor of Science in Computer Science, Example University',
            'SKILLS',
            'PHP, Laravel, PostgreSQL, Docker, AWS, Testing',
        ];
    }

    private function upload(User $user, string $contents): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->post('/api/v1/resumes', [
            'resume' => UploadedFile::fake()->createWithContent('resume.pdf', $contents),
        ], ['Accept' => 'application/json']);
    }
}
