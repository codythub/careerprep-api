<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Review;
use App\PaymentStatus;
use App\ReviewStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateReview
{
    public function __construct(private ProcessReview $processReview) {}

    public function handle(Payment $payment): Review
    {
        $review = DB::transaction(function () use ($payment): Review {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($lockedPayment->status !== PaymentStatus::Verified) {
                throw ValidationException::withMessages([
                    'payment_id' => ['This payment has not been verified.'],
                ]);
            }

            if ($lockedPayment->entitlement_consumed_at !== null || $lockedPayment->review()->exists()) {
                throw ValidationException::withMessages([
                    'payment_id' => ['This review entitlement has already been used.'],
                ]);
            }

            $review = $lockedPayment->user->reviews()->create([
                'resume_id' => $lockedPayment->resume_id,
                'payment_id' => $lockedPayment->id,
                'status' => ReviewStatus::Pending,
            ]);

            $lockedPayment->update(['entitlement_consumed_at' => now()]);

            return $review;
        }, 3);

        return $this->processReview->handle($review);
    }
}
