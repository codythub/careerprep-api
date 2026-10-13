<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReviewPricing;
use Illuminate\Http\JsonResponse;

class PricingController extends Controller
{
    public function __invoke(ReviewPricing $pricing): JsonResponse
    {
        $available = $pricing->isAvailable();

        return response()->json([
            'data' => [
                'review' => [
                    'amount' => $available ? $pricing->amount() : null,
                    'currency' => $available ? $pricing->currency() : null,
                    'available' => $available,
                ],
            ],
        ]);
    }
}
