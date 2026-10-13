<?php

namespace App\Services;

use App\Contracts\ResumeTextExtractor;
use App\Models\Resume;
use App\ResumeExtractionStatus;
use Throwable;

class ExtractResumeText
{
    public function __construct(private ResumeTextExtractor $extractor) {}

    public function handle(Resume $resume): Resume
    {
        $resume->update([
            'extraction_status' => ResumeExtractionStatus::Pending,
            'extraction_error' => null,
        ]);

        try {
            $text = trim($this->extractor->extract($resume));

            if ($text === '') {
                throw new \RuntimeException('The PDF contained no readable text.');
            }

            $resume->update([
                'extraction_status' => ResumeExtractionStatus::Succeeded,
                'extracted_text' => $text,
                'extraction_error' => null,
                'extracted_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $resume->update([
                'extraction_status' => ResumeExtractionStatus::Failed,
                'extracted_text' => null,
                'extraction_error' => 'Text could not be extracted from this PDF.',
                'extracted_at' => null,
            ]);
        }

        return $resume->refresh();
    }
}
