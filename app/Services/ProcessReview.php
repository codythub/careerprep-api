<?php

namespace App\Services;

use App\Contracts\ResumeAssessor;
use App\Models\Review;
use App\ResumeExtractionStatus;
use App\ReviewStatus;
use Throwable;

class ProcessReview
{
    public function __construct(
        private ExtractResumeText $extractResumeText,
        private ResumeAssessor $assessor,
    ) {}

    public function handle(Review $review): Review
    {
        try {
            $resume = $review->resume;

            if ($resume->extraction_status !== ResumeExtractionStatus::Succeeded) {
                $resume = $this->extractResumeText->handle($resume);
            }

            if ($resume->extraction_status !== ResumeExtractionStatus::Succeeded || blank($resume->extracted_text)) {
                $review->update([
                    'status' => ReviewStatus::Failed,
                    'failure_reason' => 'The resume text could not be extracted for assessment.',
                ]);

                return $review->refresh();
            }

            $assessment = $this->assessor->assess($resume->extracted_text);

            $review->update([
                'status' => ReviewStatus::Completed,
                'assessment_version' => $assessment['version'],
                'overall_score' => $assessment['overall_score'],
                'category_scores' => $assessment['category_scores'],
                'weaknesses' => $assessment['weaknesses'],
                'recommendations' => $assessment['recommendations'],
                'failure_reason' => null,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $review->update([
                'status' => ReviewStatus::Failed,
                'failure_reason' => 'The resume assessment could not be completed.',
            ]);
        }

        return $review->refresh();
    }
}
