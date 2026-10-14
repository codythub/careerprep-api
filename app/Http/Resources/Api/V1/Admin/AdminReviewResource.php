<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Http\Resources\Api\V1\ReviewResource;
use Illuminate\Http\Request;

/** The candidate Review fields plus a user summary and admin-granted attempts. */
class AdminReviewResource extends ReviewResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'user_id' => $this->user_id,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'extra_attempts' => $this->extra_attempts,
            'payment_status' => $this->whenLoaded('payment', fn () => $this->payment->status->value),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
