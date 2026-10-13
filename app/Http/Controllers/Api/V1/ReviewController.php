<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReviewRequest;
use App\Http\Resources\Api\V1\ReviewResource;
use App\Models\Payment;
use App\Models\Review;
use App\Services\CreateReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ReviewController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Review::class);

        $resumeId = $request->query('resume_id');

        $reviews = $request->user()->reviews()
            ->with('resume:id,original_name')
            ->when(is_string($resumeId) && ctype_digit($resumeId), fn ($query) => $query->where('resume_id', (int) $resumeId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        return ReviewResource::collection($reviews);
    }

    public function store(StoreReviewRequest $request, CreateReview $createReview): ReviewResource
    {
        Gate::authorize('create', Review::class);

        $payment = Payment::query()->findOrFail($request->integer('payment_id'));
        Gate::authorize('view', $payment);

        $review = $createReview->handle($payment);

        return new ReviewResource($review->load('resume:id,original_name'));
    }

    public function show(Review $review): ReviewResource
    {
        Gate::authorize('view', $review);

        return new ReviewResource($review->load('resume:id,original_name'));
    }

    public function download(Review $review): Response
    {
        Gate::authorize('view', $review);

        $lines = [
            'CareerPrep Resume Review',
            '========================',
            '',
            'Status: '.$review->status->value,
            'Assessment rules: '.($review->assessment_version ?? 'Not completed'),
            'Overall score: '.($review->overall_score !== null ? $review->overall_score.'/100' : 'Not available'),
            '',
            Review::DISCLAIMER,
            '',
            'Category scores',
            '---------------',
        ];

        foreach ($review->category_scores ?? [] as $category => $score) {
            $lines[] = str_replace('_', ' ', ucfirst($category)).': '.$score.'/100';
        }

        $lines[] = '';
        $lines[] = 'Weaknesses';
        $lines[] = '----------';

        foreach ($review->weaknesses ?? [] as $weakness) {
            $lines[] = '- '.$weakness;
        }

        $lines[] = '';
        $lines[] = 'Recommendations';
        $lines[] = '---------------';

        foreach ($review->recommendations ?? [] as $recommendation) {
            $lines[] = '- '.$recommendation;
        }

        if ($review->failure_reason !== null) {
            $lines[] = '';
            $lines[] = 'Processing note: '.$review->failure_reason;
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="careerprep-review-'.$review->id.'.txt"',
        ]);
    }
}
