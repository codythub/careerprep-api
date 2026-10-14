<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Resume;
use App\Models\Review;
use App\Models\User;
use App\PaymentStatus;
use App\ResumeExtractionStatus;
use App\ReviewStatus;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null;

        $inPeriod = fn (string $column) => fn (Builder $query) => $query
            ->when($from, fn (Builder $query) => $query->where($column, '>=', $from))
            ->when($to, fn (Builder $query) => $query->where($column, '<=', $to));

        $countBy = function (string $model, string $column, array $cases) use ($inPeriod): array {
            $counts = $model::query()->where($inPeriod('created_at'))
                ->selectRaw("{$column} as value, count(*) as aggregate")
                ->groupBy($column)
                ->pluck('aggregate', 'value');

            $result = ['total' => (int) $counts->sum()];

            foreach ($cases as $case) {
                $result[$case->value] = (int) ($counts[$case->value] ?? 0);
            }

            return $result;
        };

        $sumByCurrency = fn (PaymentStatus $status, string $column) => Payment::query()
            ->where('status', $status)
            ->where($inPeriod($column))
            ->selectRaw('currency, sum(amount) as amount, count(*) as payments')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => $row->currency,
                'amount' => (int) $row->amount,
                'payments' => (int) $row->payments,
            ])
            ->all();

        $retryableFailedReviews = Review::query()
            ->where($inPeriod('created_at'))
            ->where('status', ReviewStatus::Failed)
            ->whereRaw('attempts < ? + extra_attempts', [config('reviews.max_attempts')])
            ->whereHas('payment', fn (Builder $query) => $query->where('status', '!=', PaymentStatus::Refunded))
            ->count();

        return response()->json([
            'data' => [
                'period' => [
                    'from' => $from?->toISOString(),
                    'to' => $to?->toISOString(),
                ],
                'users' => $countBy(User::class, 'role', UserRole::cases()),
                'resumes' => $countBy(Resume::class, 'extraction_status', ResumeExtractionStatus::cases()),
                'payments' => $countBy(Payment::class, 'status', PaymentStatus::cases()),
                'reviews' => [
                    ...$countBy(Review::class, 'status', ReviewStatus::cases()),
                    'retryable_failed' => $retryableFailedReviews,
                ],
                'revenue' => $sumByCurrency(PaymentStatus::Verified, 'verified_at'),
                'refunds' => $sumByCurrency(PaymentStatus::Refunded, 'refunded_at'),
            ],
        ]);
    }
}
