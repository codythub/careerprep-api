<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Payment;
use App\Models\Resume;
use App\PaymentStatus;
use App\Services\PaymentVerifier;
use App\Services\PaystackClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnexpectedValueException;

class PaymentController extends Controller
{
    public function store(StorePaymentRequest $request, PaystackClient $paystack): PaymentResource
    {
        Gate::authorize('create', Payment::class);

        $resume = Resume::query()->findOrFail($request->integer('resume_id'));
        Gate::authorize('view', $resume);

        $amount = (int) config('services.paystack.review_amount');
        $currency = strtoupper((string) config('services.paystack.currency'));
        $secretKey = config('services.paystack.secret_key');

        abort_if(
            $amount < 1 || strlen($currency) !== 3 || ! is_string($secretKey) || $secretKey === '',
            503,
            'Payments are not configured.',
        );

        $payment = $request->user()->payments()->create([
            'resume_id' => $resume->id,
            'reference' => 'CP-'.Str::ulid(),
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentStatus::Pending,
        ]);

        try {
            $providerData = $paystack->initialize($payment->load('user'));

            if ($providerData['reference'] !== $payment->reference) {
                throw new UnexpectedValueException('Paystack returned an unexpected payment reference.');
            }

            $payment->update([
                'status' => PaymentStatus::Initialized,
                'authorization_url' => $providerData['authorization_url'],
                'access_code' => $providerData['access_code'],
            ]);
        } catch (Throwable $exception) {
            $payment->update(['status' => PaymentStatus::Failed]);

            throw $exception;
        }

        return new PaymentResource($payment->refresh()->load('review'));
    }

    public function verify(
        Payment $payment,
        PaystackClient $paystack,
        PaymentVerifier $paymentVerifier,
    ): PaymentResource {
        Gate::authorize('view', $payment);

        $providerData = $paystack->verify($payment->reference);
        $verifiedPayment = $paymentVerifier->markVerified($payment, $providerData);

        if ($verifiedPayment === null) {
            throw ValidationException::withMessages([
                'payment' => ['The transaction could not be verified for the expected amount and currency.'],
            ]);
        }

        return new PaymentResource($verifiedPayment->load('review'));
    }
}
