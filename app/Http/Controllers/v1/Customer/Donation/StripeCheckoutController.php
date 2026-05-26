<?php

namespace App\Http\Controllers\v1\Customer\Donation;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Donation\StripeCheckoutRequest;
use App\Http\Requests\Customer\Donation\StripeVerifyCheckoutRequest;
use App\Models\Pledge;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Admin\Transaction\TransactionService;
use App\Services\Donation\DonationIntentService;
use App\Services\Donation\DonorNameRequirement;
use App\Services\Payment\StripeCheckoutService;
use App\Services\Payment\StripeCheckoutVerificationService;
use Illuminate\Validation\ValidationException;

class StripeCheckoutController extends Controller
{
    public function __construct(
        private readonly DonationIntentService $donationIntentService,
        private readonly StripeCheckoutService $stripeCheckoutService,
        private readonly StripeCheckoutVerificationService $stripeCheckoutVerificationService,
        private readonly TransactionService $transactionService,
        private readonly DonorNameRequirement $donorNameRequirement,
    ) {}

    public function store(StripeCheckoutRequest $request)
    {
        try {
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
            $cancelUrl = $validated['cancel_url'] ?? null;
            $frontendUrl = $validated['frontend_url'] ?? null;
            unset($validated['success_url'], $validated['cancel_url'], $validated['frontend_url']);

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
                'gateway' => 'stripe',
            ]));

            $checkout = $this->stripeCheckoutService->createCheckoutSession(
                $transaction,
                $user,
                $successUrl,
                $cancelUrl,
                $frontendUrl,
            );

            $transaction = $this->transactionService->findTransaction($transaction->uuid);

            return JsonResponser::send(false, 'Stripe Checkout session created.', [
                'checkout_url' => $checkout['url'],
                'checkout_session_id' => $checkout['session_id'],
            ], 201);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\StripeCheckoutController@store');
        }
    }

    public function verify(StripeVerifyCheckoutRequest $request)
    {
        try {
            $validated = $request->validated();
            $user = $request->user();
            $user = $user instanceof User ? $user : null;

            $result = $this->stripeCheckoutVerificationService->verify(
                $validated['checkout_session_id'],
                $user,
                $validated['transaction_uuid'] ?? null,
            );

            return JsonResponser::send(false, 'Stripe checkout verified.', [
                'checkout_session_id' => $result['checkout_session_id'],
                'payment_status' => $result['payment_status'],
                'session_status' => $result['session_status'],
                'sync_action' => $result['sync_action'],
            ], 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\StripeCheckoutController@verify');
        }
    }
}
