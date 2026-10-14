<?php

namespace App\Services;

use App\Models\Resume;
use App\Models\Review;
use App\PaymentStatus;
use App\ReviewStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryReview
{
    public function __construct(private ProcessReview $processReview) {}

    /**
     * @param  bool  $supportOverride  Admin retry: ignores the attempt limit and refund state.
     */
    public function handle(Review $review, ?Resume $resume = null, bool $supportOverride = false): Review
    {
        $review = DB::transaction(function () use ($review, $resume, $supportOverride): Review {
            $lockedReview = Review::query()->lockForUpdate()->findOrFail($review->getKey());

            if ($lockedReview->status === ReviewStatus::Completed) {
                throw ValidationException::withMessages([
                    'review' => ['This review has already been completed.'],
                ]);
            }

            if (! $supportOverride && $lockedReview->payment->status === PaymentStatus::Refunded) {
                throw ValidationException::withMessages([
                    'review' => ['The payment for this review has been refunded.'],
                ]);
            }

            if (! $supportOverride && ! $lockedReview->hasAttemptsRemaining()) {
                throw ValidationException::withMessages([
                    'review' => ['This review has no retry attempts remaining. Please contact support.'],
                ]);
            }

            if (! $lockedReview->isAwaitingRetry()) {
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
