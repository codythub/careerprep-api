<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
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
            'payment_id' => $this->payment_id,
            'resume_name' => $this->whenLoaded('resume', fn () => $this->resume->original_name),
            'status' => $this->status->value,
            'assessment_version' => $this->assessment_version,
            'overall_score' => $this->overall_score,
            'category_scores' => $this->category_scores,
            'weaknesses' => $this->weaknesses,
            'recommendations' => $this->recommendations,
            'failure_reason' => $this->failure_reason,
            'attempts' => $this->attempts,
            'max_attempts' => config('reviews.max_attempts'),
            'can_retry' => $this->isRetryable(),
            'disclaimer' => Review::DISCLAIMER,
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
