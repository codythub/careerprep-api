<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentVerifier $paymentVerifier): JsonResponse
    {
        $secretKey = config('services.paystack.secret_key');

        abort_if(! is_string($secretKey) || $secretKey === '', 503, 'Payments are not configured.');

        $payload = $request->getContent();
        $providedSignature = (string) $request->header('x-paystack-signature');
        $expectedSignature = hash_hmac('sha512', $payload, $secretKey);

        abort_unless($providedSignature !== '' && hash_equals($expectedSignature, $providedSignature), 401);

        $event = json_decode($payload, true);

        abort_unless(is_array($event), 400, 'Invalid webhook payload.');

        if (($event['event'] ?? null) !== 'charge.success' || ! is_array($event['data'] ?? null)) {
            return response()->json(['message' => 'Webhook acknowledged.']);
        }

        $reference = $event['data']['reference'] ?? null;

        if (is_string($reference)) {
            $payment = Payment::query()->where('reference', $reference)->first();

            if ($payment !== null) {
                $paymentVerifier->markVerified($payment, $event['data']);
            }
        }

        return response()->json(['message' => 'Webhook acknowledged.']);
    }
}
