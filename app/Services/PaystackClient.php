<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use UnexpectedValueException;

class PaystackClient
{
    /** @return array{authorization_url: string, access_code: string, reference: string} */
    public function initialize(Payment $payment): array
    {
        $payload = [
            'email' => $payment->user->email,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'metadata' => [
                'payment_id' => $payment->id,
                'resume_id' => $payment->resume_id,
                'user_id' => $payment->user_id,
            ],
        ];

        $callbackUrl = config('services.paystack.callback_url');

        if (is_string($callbackUrl) && $callbackUrl !== '') {
            $payload['callback_url'] = $callbackUrl;
        }

        $response = $this->request()->post('/transaction/initialize', $payload)->throw();
        $data = $response->json('data');

        if ($response->json('status') !== true || ! is_array($data)) {
            throw new UnexpectedValueException('Paystack returned an invalid initialization response.');
        }

        foreach (['authorization_url', 'access_code', 'reference'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || $data[$key] === '') {
                throw new UnexpectedValueException('Paystack returned incomplete initialization data.');
            }
        }

        return [
            'authorization_url' => $data['authorization_url'],
            'access_code' => $data['access_code'],
            'reference' => $data['reference'],
        ];
    }

    /** @return array<string, mixed> */
    public function verify(string $reference): array
    {
        $response = $this->request()
            ->get('/transaction/verify/'.rawurlencode($reference))
            ->throw();
        $data = $response->json('data');

        if ($response->json('status') !== true || ! is_array($data)) {
            throw new UnexpectedValueException('Paystack returned an invalid verification response.');
        }

        return $data;
    }

    private function request(): PendingRequest
    {
        $secretKey = config('services.paystack.secret_key');
        $baseUrl = config('services.paystack.base_url');

        if (! is_string($secretKey) || $secretKey === '' || ! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('Paystack is not configured.');
        }

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->withToken($secretKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }
}
