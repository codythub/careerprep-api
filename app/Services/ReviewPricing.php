<?php

namespace App\Services;

class ReviewPricing
{
    /** Amount charged per review, in the currency's smallest unit (e.g. kobo). */
    public function amount(): int
    {
        return (int) config('services.paystack.review_amount');
    }

    public function currency(): string
    {
        return strtoupper((string) config('services.paystack.currency'));
    }

    public function isAvailable(): bool
    {
        $secretKey = config('services.paystack.secret_key');

        return $this->amount() >= 1
            && strlen($this->currency()) === 3
            && is_string($secretKey)
            && $secretKey !== '';
    }
}
