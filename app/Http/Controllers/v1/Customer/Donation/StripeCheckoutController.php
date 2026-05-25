<?php

namespace App\Http\Controllers\v1\Customer\Donation;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Donation\StripeGuestCheckoutRequest;
use App\Http\Requests\Customer\Donation\StripeMemberCheckoutRequest;
use App\Http\Requests\Customer\Donation\StripeVerifyCheckoutRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Pledge;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Admin\Transaction\TransactionService;
use App\Services\Donation\DonationIntentService;
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
    ) {}

    public function guest(StripeGuestCheckoutRequest $request)
    {
        try {
            $validated = $request->validated();
            $successUrl = $validated['success_url'] ?? null;
            $cancelUrl = $validated['cancel_url'] ?? null;
            $frontendUrl = $validated['frontend_url'] ?? null;
            unset($validated['success_url'], $validated['cancel_url'], $validated['frontend_url']);

            $transaction = $this->donationIntentService->createPendingIntent(array_merge($validated, [
                'gateway' => 'stripe',
            ]));

            $checkout = $this->stripeCheckoutService->createCheckoutSession(
                $transaction,
                null,
                $successUrl,
                $cancelUrl,
                $frontendUrl,
            );

            $transaction = $this->transactionService->findTransaction($transaction->uuid);

            return JsonResponser::send(false, 'Stripe Checkout session created.', [
                'checkout_url' => $checkout['url'],
                'checkout_session_id' => $checkout['session_id'],
                // 'transaction' => TransactionResource::make($transaction)->resolve(),
            ], 201);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\StripeCheckoutController@guest');
        }
    }

    public function member(StripeMemberCheckoutRequest $request)
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(401);
            }

            $validated = $request->validated();

            if (! empty($validated['pledge_uuid'])) {
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

            $validated['user_uuid'] = $user->uuid;

            if (! isset($validated['donor_email']) || ! is_string($validated['donor_email']) || trim($validated['donor_email']) === '') {
                $validated['donor_email'] = $user->email;
            }

            if (
                ! isset($validated['donor_name'])
                || ! is_string($validated['donor_name'])
                || trim($validated['donor_name']) === ''
            ) {
                $validated['donor_name'] = trim(implode(' ', array_filter([
                    (string) ($user->firstname ?? ''),
                    (string) ($user->lastname ?? ''),
                ])));
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
                // 'transaction' => TransactionResource::make($transaction)->resolve(),
            ], 201);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\StripeCheckoutController@member');
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
                // 'transaction' => TransactionResource::make($result['transaction'])->resolve(),
            ], 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\StripeCheckoutController@verify');
        }
    }
}
