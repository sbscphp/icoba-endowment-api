<?php

namespace App\Services\Admin\Transaction;

use App\Enums\PaymentGateway;
use App\Exceptions\ApiException;
use App\Models\Transaction;
use App\Services\Payment\FcmbCheckoutVerificationService;
use App\Services\Payment\PaystackCheckoutVerificationService;
use App\Services\Payment\StripeCheckoutVerificationService;

/**
 * Admin-triggered re-query of a hosted-checkout transaction against its gateway.
 *
 * Reuses the customer-facing verification services so the state transitions
 * (finalize on paid, mark failed on failure) are identical to the webhook and
 * the donor's post-redirect verify call. No ownership check is applied because
 * the caller is an authenticated admin.
 */
final class TransactionGatewayVerificationService
{
    public function __construct(
        private readonly StripeCheckoutVerificationService $stripeVerification,
        private readonly PaystackCheckoutVerificationService $paystackVerification,
        private readonly FcmbCheckoutVerificationService $fcmbVerification,
    ) {}

    /**
     * @return array{
     *     payment_gateway: string,
     *     checkout_session_id: string,
     *     payment_status: string,
     *     session_status: string,
     *     sync_action: string,
     *     receipt_number: string|null,
     *     transaction: Transaction
     * }
     */
    public function verify(Transaction $transaction): array
    {
        $gateway = is_string($transaction->gateway) ? PaymentGateway::tryFrom($transaction->gateway) : null;
        if ($gateway === null) {
            throw new ApiException('This transaction was not created through a verifiable payment gateway.', 422);
        }

        $reference = $transaction->gateway_reference;
        if (! is_string($reference) || trim($reference) === '') {
            throw new ApiException('This transaction has no gateway reference to verify against.', 422);
        }

        $result = match ($gateway) {
            PaymentGateway::Stripe => $this->stripeVerification->verify($reference, null, $transaction->uuid),
            PaymentGateway::Paystack => $this->paystackVerification->verify($reference, null, $transaction->uuid),
            PaymentGateway::Fcmb => $this->fcmbVerification->verify($reference, null, $transaction->uuid),
        };

        return array_merge(['payment_gateway' => $gateway->value], $result);
    }
}
