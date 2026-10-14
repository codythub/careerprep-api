<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCandidate;
use App\PaymentStatus;
use App\ReviewStatus;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'resume_id',
    'payment_id',
    'status',
    'assessment_version',
    'overall_score',
    'category_scores',
    'weaknesses',
    'recommendations',
    'failure_reason',
    'completed_at',
    'attempts',
    'extra_attempts',
])]
class Review extends Model
{
    public const DISCLAIMER = 'This automated rule-based review is guidance only. It is not an ATS score and does not guarantee hiring outcomes.';

    /** @use HasFactory<ReviewFactory> */
    use BelongsToCandidate, HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Resume, $this> */
    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** Configured attempt limit plus any attempts granted by an admin. */
    public function maxAttempts(): int
    {
        return config('reviews.max_attempts') + $this->extra_attempts;
    }

    public function hasAttemptsRemaining(): bool
    {
        return $this->attempts < $this->maxAttempts();
    }

    /**
     * A failed review, or one whose attempt stalled while pending, can be processed again.
     */
    public function isAwaitingRetry(): bool
    {
        return $this->status === ReviewStatus::Failed
            || ($this->status === ReviewStatus::Pending
                && $this->updated_at?->lte(now()->subMinutes(config('reviews.stale_pending_minutes'))));
    }

    public function isRetryable(): bool
    {
        return $this->isAwaitingRetry()
            && $this->hasAttemptsRemaining()
            && $this->payment?->status !== PaymentStatus::Refunded;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'overall_score' => 'integer',
            'attempts' => 'integer',
            'extra_attempts' => 'integer',
            'category_scores' => 'array',
            'weaknesses' => 'array',
            'recommendations' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
