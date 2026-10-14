<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Never exposes provider_payload, access_code or authorization_url. */
class AdminPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'resume_id' => $this->resume_id,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'entitlement_status' => $this->resource->entitlementStatus(),
            'review_id' => $this->whenLoaded('review', fn () => $this->review?->id),
            'provider_transaction_id' => $this->provider_transaction_id,
            'verified_at' => $this->verified_at?->toISOString(),
            'refunded_at' => $this->refunded_at?->toISOString(),
            'refund_note' => $this->refund_note,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
