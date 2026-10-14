<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\MarkPaymentRefundedRequest;
use App\Http\Resources\Api\V1\Admin\AdminPaymentResource;
use App\Models\Payment;
use App\PaymentStatus;
use App\Services\AdminAudit;
use App\Services\PaymentVerifier;
use App\Services\PaystackClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

class PaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $payments = Payment::query()
            ->with(['user:id,name,email', 'review:id,payment_id'])
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['reference'] ?? null, fn (Builder $query, string $reference) => $query->where('reference', $reference))
            ->when($validated['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->when($validated['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($validated['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return AdminPaymentResource::collection($payments);
    }

    public function show(Payment $payment): AdminPaymentResource
    {
        return new AdminPaymentResource($payment->load(['user:id,name,email', 'review:id,payment_id']));
    }

    public function reverify(
        Payment $payment,
        PaystackClient $paystack,
        PaymentVerifier $verifier,
        AdminAudit $audit,
    ): AdminPaymentResource {
        if ($payment->status === PaymentStatus::Refunded) {
            throw ValidationException::withMessages([
                'payment' => ['Refunded payments cannot be re-verified.'],
            ]);
        }

        $secretKey = config('services.paystack.secret_key');
        abort_if(! is_string($secretKey) || $secretKey === '', 503, 'Payments are not configured.');

        $before = $payment->status->value;

        try {
            $providerData = $paystack->verify($payment->reference);
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            abort(502, 'Paystack could not be reached or returned an invalid response.');
        }

        $verified = $verifier->markVerified($payment, $providerData) !== null;
        $payment->refresh();
        $providerStatus = is_string($providerData['status'] ?? null) ? $providerData['status'] : null;

        $audit->record('payment.reverify', $payment, ['status' => $before], [
            'status' => $payment->status->value,
            'provider_status' => $providerStatus,
            'verified' => $verified,
        ]);

        return (new AdminPaymentResource($payment->load(['user:id,name,email', 'review:id,payment_id'])))
            ->additional(['meta' => ['verified' => $verified, 'provider_status' => $providerStatus]]);
    }

    public function markRefunded(MarkPaymentRefundedRequest $request, Payment $payment, AdminAudit $audit): AdminPaymentResource
    {
        $note = $request->string('note')->trim()->toString();

        [$payment, $before] = DB::transaction(function () use ($payment, $note): array {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($locked->status === PaymentStatus::Refunded) {
                throw ValidationException::withMessages([
                    'payment' => ['This payment is already marked as refunded.'],
                ]);
            }

            if ($locked->status !== PaymentStatus::Verified) {
                throw ValidationException::withMessages([
                    'payment' => ['Only verified payments can be marked as refunded.'],
                ]);
            }

            $before = ['status' => $locked->status->value, 'entitlement_status' => $locked->entitlementStatus()];

            // Records a refund already made in the Paystack dashboard. No money moves here.
            $locked->update([
                'status' => PaymentStatus::Refunded,
                'refunded_at' => now(),
                'refund_note' => $note,
            ]);

            return [$locked, $before];
        }, 3);

        $audit->record('payment.mark_refunded', $payment, $before, [
            'status' => $payment->status->value,
            'entitlement_status' => $payment->entitlementStatus(),
            'note' => $note,
        ]);

        return new AdminPaymentResource($payment->load(['user:id,name,email', 'review:id,payment_id']));
    }
}
