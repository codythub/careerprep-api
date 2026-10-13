<?php

namespace App\Models;

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
])]
class Review extends Model
{
    public const DISCLAIMER = 'This automated rule-based review is guidance only. It is not an ATS score and does not guarantee hiring outcomes.';

    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'overall_score' => 'integer',
            'category_scores' => 'array',
            'weaknesses' => 'array',
            'recommendations' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
