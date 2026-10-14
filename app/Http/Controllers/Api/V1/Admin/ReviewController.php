<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminRetryReviewRequest;
use App\Http\Resources\Api\V1\Admin\AdminReviewResource;
use App\Models\Resume;
use App\Models\Review;
use App\ReviewStatus;
use App\Services\AdminAudit;
use App\Services\RetryReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    private const RELATIONS = ['user:id,name,email', 'resume:id,original_name', 'payment:id,status'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ReviewStatus::class)],
            'user_id' => ['nullable', 'integer'],
            'resume_id' => ['nullable', 'integer'],
        ]);

        $reviews = Review::query()
            ->with(self::RELATIONS)
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->when($validated['resume_id'] ?? null, fn (Builder $query, int $resumeId) => $query->where('resume_id', $resumeId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return AdminReviewResource::collection($reviews);
    }

    public function show(Review $review): AdminReviewResource
    {
        return new AdminReviewResource($review->load(self::RELATIONS));
    }

    public function retry(AdminRetryReviewRequest $request, Review $review, RetryReview $retryReview, AdminAudit $audit): AdminReviewResource
    {
        $resume = null;

        if ($request->has('resume_id')) {
            $resume = Resume::query()->findOrFail($request->integer('resume_id'));

            if ($resume->user_id !== $review->user_id) {
                throw ValidationException::withMessages([
                    'resume_id' => ['The resume must belong to the same candidate as the review.'],
                ]);
            }
        }

        $before = $this->snapshot($review);
        $review = $retryReview->handle($review, $resume, supportOverride: true);

        $audit->record('review.retry', $review, $before, $this->snapshot($review));

        return new AdminReviewResource($review->load(self::RELATIONS));
    }

    public function grantAttempt(Review $review, AdminAudit $audit): AdminReviewResource
    {
        [$review, $before] = DB::transaction(function () use ($review): array {
            $locked = Review::query()->lockForUpdate()->findOrFail($review->getKey());

            if ($locked->status === ReviewStatus::Completed) {
                throw ValidationException::withMessages([
                    'review' => ['Completed reviews do not need more attempts.'],
                ]);
            }

            $before = $this->snapshot($locked);
            $locked->update(['extra_attempts' => $locked->extra_attempts + 1]);

            return [$locked, $before];
        }, 3);

        $audit->record('review.grant_attempt', $review, $before, $this->snapshot($review));

        return new AdminReviewResource($review->load(self::RELATIONS));
    }

    /** @return array<string, mixed> */
    private function snapshot(Review $review): array
    {
        return [
            'status' => $review->status->value,
            'resume_id' => $review->resume_id,
            'attempts' => $review->attempts,
            'max_attempts' => $review->maxAttempts(),
        ];
    }
}
