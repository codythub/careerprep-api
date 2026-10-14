<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCandidate;
use App\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'resume_id',
    'reference',
    'amount',
    'currency',
    'status',
    'authorization_url',
    'access_code',
    'provider_transaction_id',
    'provider_payload',
    'verified_at',
    'entitlement_consumed_at',
    'refunded_at',
    'refund_note',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
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

    /** @return HasOne<Review, $this> */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /** available = verified and unused; consumed = a review was created; otherwise unavailable. */
    public function entitlementStatus(): string
    {
        if ($this->entitlement_consumed_at !== null) {
            return 'consumed';
        }

        return $this->status === PaymentStatus::Verified ? 'available' : 'unavailable';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'provider_payload' => 'array',
            'verified_at' => 'datetime',
            'entitlement_consumed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }
}
