<?php

namespace App\Services;

use App\Models\Resume;
use App\Models\Review;
use App\ReviewStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryReview
{
    public function __construct(private ProcessReview $processReview) {}

    public function handle(Review $review, ?Resume $resume = null): Review
    {
        $review = DB::transaction(function () use ($review, $resume): Review {
            $lockedReview = Review::query()->lockForUpdate()->findOrFail($review->getKey());

            if ($lockedReview->status === ReviewStatus::Completed) {
                throw ValidationException::withMessages([
                    'review' => ['This review has already been completed.'],
                ]);
            }

            if (! $lockedReview->hasAttemptsRemaining()) {
                throw ValidationException::withMessages([
                    'review' => ['This review has no retry attempts remaining. Please contact support.'],
                ]);
            }

            if (! $lockedReview->isRetryable()) {
                throw ValidationException::withMessages([
                    'review' => ['This review is still being processed.'],
                ]);
            }

            $lockedReview->update([
                'resume_id' => $resume?->getKey() ?? $lockedReview->resume_id,
                'status' => ReviewStatus::Pending,
                'attempts' => $lockedReview->attempts + 1,
                'failure_reason' => null,
            ]);

            return $lockedReview;
        }, 3);

        return $this->processReview->handle($review);
    }
}
