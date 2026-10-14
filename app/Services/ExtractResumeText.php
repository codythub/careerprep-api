<?php

namespace App\Services;

use App\Contracts\ResumeTextExtractor;
use App\Models\Resume;
use App\ResumeExtractionStatus;
use Throwable;

class ExtractResumeText
{
    /** Minimum words (2+ letters) for text to be worth assessing. */
    public const MIN_WORDS = 30;

    /** Minimum share of letters among non-whitespace characters. */
    public const MIN_LETTER_RATIO = 0.5;

    public function __construct(private ResumeTextExtractor $extractor) {}

    public function handle(Resume $resume): Resume
    {
        $resume->update([
            'extraction_status' => ResumeExtractionStatus::Pending,
            'extraction_error' => null,
        ]);

        try {
            $text = $this->normalize($this->extractor->extract($resume));
        } catch (Throwable $exception) {
            report($exception);

            return $this->markFailed($resume, 'Text could not be extracted from this PDF.');
        }

        if (! $this->isReadable($text)) {
            return $this->markFailed(
                $resume,
                'Not enough readable text was found in this PDF. Upload a text-based PDF, not a scanned image.',
            );
        }

        $resume->update([
            'extraction_status' => ResumeExtractionStatus::Succeeded,
            'extracted_text' => $text,
            'extraction_error' => null,
            'extracted_at' => now(),
        ]);

        return $resume->refresh();
    }

    /**
     * Force valid UTF-8 (PostgreSQL rejects invalid byte sequences) and tidy whitespace.
     */
    private function normalize(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', ' ', $text);
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Reject text that is too short or mostly non-letters, which indicates the PDF's
     * fonts could not be decoded and an assessment would be meaningless.
     */
    private function isReadable(string $text): bool
    {
        $words = preg_match_all('/\p{L}{2,}/u', $text);
        $letters = preg_match_all('/\p{L}/u', $text);
        $visible = preg_match_all('/\S/u', $text);

        return $words >= self::MIN_WORDS
            && $visible > 0
            && $letters / $visible >= self::MIN_LETTER_RATIO;
    }

    private function markFailed(Resume $resume, string $reason): Resume
    {
        $resume->update([
            'extraction_status' => ResumeExtractionStatus::Failed,
            'extracted_text' => null,
            'extraction_error' => $reason,
            'extracted_at' => null,
        ]);

        return $resume->refresh();
    }
}
