<?php

namespace App\Http\Resources\Api\V1;

use App\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resume_id' => $this->resume_id,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'authorization_url' => $this->authorization_url,
            'access_code' => $this->access_code,
            'entitlement_status' => $this->entitlementStatus(),
            'review_id' => $this->whenLoaded('review', fn () => $this->review?->id),
            'verified_at' => $this->verified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function entitlementStatus(): string
    {
        if ($this->entitlement_consumed_at !== null) {
            return 'consumed';
        }

        return $this->status === PaymentStatus::Verified ? 'available' : 'unavailable';
    }
}
