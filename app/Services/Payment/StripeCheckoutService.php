<?php

namespace App\Services\Payment;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

final class StripeCheckoutService
{
    /** @var list<string> */
    private const ZERO_DECIMAL = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    /** @var list<string> */
    private const THREE_DECIMAL = ['bhd', 'jod', 'kwd', 'omr', 'tnd'];

    private StripeClient $stripe;

    public function __construct(
        private readonly CheckoutRedirectResolver $redirectResolver,
    ) {
        $secret = config('services.stripe.secret');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Stripe is not configured (STRIPE_SECRET).');
        }
        $this->stripe = new StripeClient($secret);
    }

    /**
     * Create a Checkout Session and persist its id on the pending transaction.
     *
     * @return array{session_id: string, url: string, success_url: string, failed_url: string}
     *
     * @throws ApiErrorException
     * @throws ValidationException
     */
    public function createCheckoutSession(
        Transaction $transaction,
        ?User $donorUser,
        ?string $successUrl,
        ?string $failedUrl,
        ?string $legacyCancelUrl = null,
        ?string $frontendUrl = null,
    ): array {
        $currency = strtolower((string) $transaction->currency);
        $unitAmount = $this->unitAmount((float) $transaction->amount, $currency);

        $redirects = $this->redirectResolver->resolve(
            'stripe',
            $successUrl,
            $failedUrl,
            $legacyCancelUrl,
            $frontendUrl,
        );

        $params = [
            'mode' => 'payment',
            'client_reference_id' => $transaction->uuid,
            'success_url' => $redirects['success_url'],
            'cancel_url' => $redirects['failed_url'],
            'metadata' => [
                'transaction_uuid' => $transaction->uuid,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'transaction_uuid' => $transaction->uuid,
                ],
            ],
            'line_items' => [
                [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $unitAmount,
                        'product_data' => [
                            'name' => $transaction->pledge_uuid !== null ? 'Pledge payment' : 'Donation',
                            'metadata' => [
                                'transaction_uuid' => $transaction->uuid,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if ($donorUser !== null) {
            $params['customer'] = $this->resolveStripeCustomerId($donorUser);
        } else {
            $email = $transaction->donor_email;
            if (! is_string($email) || trim($email) === '') {
                throw new RuntimeException('Guest Stripe Checkout requires donor_email on the transaction.');
            }
            $params['customer_email'] = $email;
        }

        try {
            $session = $this->stripe->checkout->sessions->create($params);
        } catch (InvalidRequestException $e) {
            if ($this->isUnsupportedCheckoutAmount($e)) {
                throw ValidationException::withMessages([
                    'amount' => ['Amount not supported.'],
                ]);
            }

            throw $e;
        }

        $transaction->forceFill([
            'gateway' => 'stripe',
            'gateway_reference' => $session->id,
        ])->save();

        $this->redirectResolver->persistOnTransaction(
            $transaction,
            $redirects['success_url'],
            $redirects['failed_url'],
        );

        return [
            'session_id' => $session->id,
            'url' => (string) $session->url,
            'success_url' => $redirects['success_url'],
            'failed_url' => $redirects['failed_url'],
        ];
    }

    /**
     * @throws ApiErrorException
     */
    public function retrieveCheckoutSession(string $sessionId): Session
    {
        return $this->stripe->checkout->sessions->retrieve($sessionId);
    }

    private function resolveStripeCustomerId(User $user): string
    {
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            return $user->stripe_customer_id;
        }

        $customer = $this->stripe->customers->create([
            'email' => $user->email,
            'metadata' => [
                'user_uuid' => $user->uuid,
            ],
        ]);

        $user->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    private function unitAmount(float $amount, string $currencyLower): int
    {
        if (in_array($currencyLower, self::THREE_DECIMAL, true)) {
            return (int) round($amount * 1000);
        }
        if (in_array($currencyLower, self::ZERO_DECIMAL, true)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }

    private function isUnsupportedCheckoutAmount(InvalidRequestException $exception): bool
    {
        return in_array($exception->getStripeCode(), ['amount_too_small', 'amount_too_large'], true);
    }
}
