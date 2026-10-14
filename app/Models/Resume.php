<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCandidate;
use App\ResumeExtractionStatus;
use Database\Factories\ResumeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'disk',
    'path',
    'original_name',
    'mime_type',
    'size',
    'extraction_status',
    'extracted_text',
    'extraction_error',
    'extracted_at',
])]
class Resume extends Model
{
    /** @use HasFactory<ResumeFactory> */
    use BelongsToCandidate, HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'extraction_status' => ResumeExtractionStatus::class,
            'extracted_at' => 'datetime',
        ];
    }
}
