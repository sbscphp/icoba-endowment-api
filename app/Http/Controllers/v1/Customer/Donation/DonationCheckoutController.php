<?php

namespace App\Http\Controllers\v1\Customer\Donation;

use App\Enums\PaymentGateway;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Donation\DonationCheckoutRequest;
use App\Http\Requests\Customer\Donation\DonationVerifyCheckoutRequest;
use App\Models\Pledge;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Admin\Transaction\TransactionService;
use App\Services\Donation\DonationIntentService;
use App\Services\Donation\DonorNameRequirement;
use App\Services\Payment\CheckoutRedirectResolver;
use App\Services\Payment\PaystackCheckoutService;
use App\Services\Payment\PaystackCheckoutVerificationService;
use App\Services\Payment\StripeCheckoutService;
use App\Services\Payment\StripeCheckoutVerificationService;
use Illuminate\Validation\ValidationException;

class DonationCheckoutController extends Controller
{
    public function __construct(
        private readonly DonationIntentService $donationIntentService,
        private readonly StripeCheckoutService $stripeCheckoutService,
        private readonly StripeCheckoutVerificationService $stripeCheckoutVerificationService,
        private readonly PaystackCheckoutService $paystackCheckoutService,
        private readonly PaystackCheckoutVerificationService $paystackCheckoutVerificationService,
        private readonly TransactionService $transactionService,
        private readonly DonorNameRequirement $donorNameRequirement,
        private readonly CheckoutRedirectResolver $checkoutRedirectResolver,
    ) {}

    public function store(DonationCheckoutRequest $request)
    {
        try {
            $paymentGateway = $request->paymentGateway();
            $this->assertGatewayImplemented($paymentGateway);

            $user = $request->user();
            $user = $user instanceof User ? $user : null;

            $validated = $request->validated();

            if ($user !== null && ! empty($validated['pledge_uuid'])) {
                $pledge = Pledge::query()->where('uuid', $validated['pledge_uuid'])->firstOrFail();
                if ($pledge->user_uuid !== null && $pledge->user_uuid !== $user->uuid) {
                    throw ValidationException::withMessages([
                        'pledge_uuid' => ['This pledge is not linked to your account.'],
                    ]);
                }
            }

            $successUrl = $validated['success_url'] ?? null;
            $failedUrl = $validated['failed_url'] ?? null;
            $cancelUrl = $validated['cancel_url'] ?? null;
            $frontendUrl = $validated['frontend_url'] ?? null;
            unset(
                $validated['success_url'],
                $validated['failed_url'],
                $validated['cancel_url'],
                $validated['frontend_url'],
                $validated['payment_gateway'],
            );

            if ($user !== null) {
                $validated['user_uuid'] = $user->uuid;

                if (! isset($validated['donor_email']) || ! is_string($validated['donor_email']) || trim($validated['donor_email']) === '') {
                    $validated['donor_email'] = $user->email;
                }

                $resolvedName = $this->donorNameRequirement->resolveFromPayload($validated, $user);
                if ($resolvedName !== null) {
                    $validated['donor_name'] = $resolvedName;
                    if (filled($user->organization_name) && trim((string) ($validated['organization_name'] ?? '')) === '') {
                        $validated['organization_name'] = trim((string) $user->organization_name);
                    }
                }
            }

            $transaction = $this->donationIntentService->createPendingIntent(array_merge($validated, [
                'gateway' => $paymentGateway->value,
            ]));

            $checkout = match ($paymentGateway) {
                PaymentGateway::Stripe => $this->stripeCheckoutService->createCheckoutSession(
                    $transaction,
                    $user,
                    $successUrl,
                    $failedUrl,
                    $cancelUrl,
                    $frontendUrl,
                ),
                PaymentGateway::Paystack => $this->paystackCheckoutService->createCheckoutSession(
                    $transaction,
                    $user,
                    $successUrl,
                    $failedUrl,
                    $cancelUrl,
                    $frontendUrl,
                ),
                default => throw new ApiException('This payment gateway is not yet supported.', 501),
            };

            $transaction = $this->transactionService->findTransaction($transaction->uuid);

            return JsonResponser::send(false, 'Checkout session created.', [
                'payment_gateway' => $paymentGateway->value,
                'checkout_url' => $checkout['url'],
                'checkout_session_id' => $checkout['session_id'],
                'transaction_uuid' => $transaction->uuid,
                'success_url' => $checkout['success_url'],
                'failed_url' => $checkout['failed_url'],
            ], 201);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\DonationCheckoutController@store');
        }
    }

    public function verify(DonationVerifyCheckoutRequest $request)
    {
        try {
            $paymentGateway = $request->paymentGateway();
            $this->assertGatewayImplemented($paymentGateway);

            $validated = $request->validated();
            $user = $request->user();
            $user = $user instanceof User ? $user : null;

            $result = match ($paymentGateway) {
                PaymentGateway::Stripe => $this->stripeCheckoutVerificationService->verify(
                    $validated['checkout_session_id'],
                    $user,
                    $validated['transaction_uuid'] ?? null,
                ),
                PaymentGateway::Paystack => $this->paystackCheckoutVerificationService->verify(
                    $validated['checkout_session_id'],
                    $user,
                    $validated['transaction_uuid'] ?? null,
                ),
                default => throw new ApiException('This payment gateway is not yet supported.', 501),
            };

            return JsonResponser::send(false, 'Checkout verified.', [
                'payment_gateway' => $paymentGateway->value,
                'checkout_session_id' => $result['checkout_session_id'],
                'payment_status' => $result['payment_status'],
                'session_status' => $result['session_status'],
                'sync_action' => $result['sync_action'],
                'receipt_number' => $result['transaction']->receipt_number,
                'redirect_url' => $this->checkoutRedirectResolver->redirectForPaymentStatus(
                    $result['transaction'],
                    $result['payment_status'],
                ),
                'success_url' => data_get($result['transaction']->metadata, 'checkout_redirects.success_url'),
                'failed_url' => data_get($result['transaction']->metadata, 'checkout_redirects.failed_url'),
            ], 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\DonationCheckoutController@verify');
        }
    }

    private function assertGatewayImplemented(PaymentGateway $paymentGateway): void
    {
        if (! $paymentGateway->isImplemented()) {
            throw new ApiException(
                sprintf('%s checkout is not yet available.', ucfirst($paymentGateway->value)),
                501,
            );
        }
    }
}
