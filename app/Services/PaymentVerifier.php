<?php

namespace App\Services;

use App\Models\Payment;
use App\PaymentStatus;
use Illuminate\Support\Facades\DB;

class PaymentVerifier
{
    /** @param array<string, mixed> $providerData */
    public function markVerified(Payment $payment, array $providerData): ?Payment
    {
        if (! $this->matchesExpectedPayment($payment, $providerData)) {
            return null;
        }

        return DB::transaction(function () use ($payment, $providerData): Payment {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            // Verified payments are idempotent; refunded payments must never be re-verified.
            if (in_array($lockedPayment->status, [PaymentStatus::Verified, PaymentStatus::Refunded], true)) {
                return $lockedPayment;
            }

            $lockedPayment->update([
                'status' => PaymentStatus::Verified,
                'provider_transaction_id' => isset($providerData['id']) ? (string) $providerData['id'] : null,
                'provider_payload' => $providerData,
                'verified_at' => now(),
            ]);

            return $lockedPayment->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $providerData */
    private function matchesExpectedPayment(Payment $payment, array $providerData): bool
    {
        return ($providerData['status'] ?? null) === 'success'
            && ($providerData['reference'] ?? null) === $payment->reference
            && (int) ($providerData['amount'] ?? -1) === $payment->amount
            && strtoupper((string) ($providerData['currency'] ?? '')) === $payment->currency;
    }
}
