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
    public const PLATFORM_PREFIX = 'ICB-ENMT-';

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

        $redirects = $this->redirectResolver->resolve(
            'stripe',
            $successUrl,
            $failedUrl,
            $legacyCancelUrl,
            $frontendUrl,
        );

        $referenceId = self::PLATFORM_PREFIX.$transaction->uuid;

        $metadata = [
            'transaction_uuid' => $referenceId,
            'transaction_id' => (string) $transaction->transaction_id,
            'donation_amount' => number_format((float) $transaction->amount, 2, '.', ''),
            'service_fee' => number_format((float) ($transaction->fee_amount ?? 0), 2, '.', ''),
            'is_test' => $transaction->is_test ? 'true' : 'false',
        ];

        $params = [
            'mode' => 'payment',
            'client_reference_id' => $referenceId,
            'success_url' => $redirects['success_url'],
            'cancel_url' => $redirects['failed_url'],
            'metadata' => $metadata,
            'payment_intent_data' => [
                'metadata' => $metadata,
            ],
            'line_items' => $this->buildLineItems($transaction, $currency, $referenceId),
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
            try {
                $session = $this->stripe->checkout->sessions->create($params);
            } catch (InvalidRequestException $e) {
                if ($donorUser === null || ! $this->isMissingCustomer($e)) {
                    throw $e;
                }

                // Stored customer id is unusable with this key (test-mode id, deleted customer, …): replace it and retry once.
                $donorUser->forceFill(['stripe_customer_id' => null])->save();
                $params['customer'] = $this->resolveStripeCustomerId($donorUser);
                $session = $this->stripe->checkout->sessions->create($params);
            }
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

    /**
     * The fee Stripe kept on this session's charge, in the transaction currency, or null when
     * it is not available yet or was settled in another currency.
     *
     * Best effort: never throws, so finalization is not blocked by a lookup failure.
     *
     * @return array{fee: float|null, currency: string|null}
     */
    public function resolveGatewayFee(Session $session): array
    {
        $paymentIntentId = is_string($session->payment_intent) ? $session->payment_intent : null;
        if ($paymentIntentId === null || $paymentIntentId === '') {
            return ['fee' => null, 'currency' => null];
        }

        try {
            $intent = $this->stripe->paymentIntents->retrieve($paymentIntentId, [
                'expand' => ['latest_charge.balance_transaction'],
            ]);
            $balance = $intent->latest_charge?->balance_transaction ?? null;
            if (! is_object($balance) || ! isset($balance->fee, $balance->currency)) {
                return ['fee' => null, 'currency' => null];
            }

            $currency = strtoupper((string) $balance->currency);

            return [
                'fee' => MinorUnits::toMajor((int) $balance->fee, $currency),
                'currency' => $currency,
            ];
        } catch (\Throwable) {
            return ['fee' => null, 'currency' => null];
        }
    }

    /**
     * Donation and service fee as separate lines so the Stripe receipt itemises them.
     * The sum of the lines is the charged figure (total_amount).
     *
     * @return list<array<string, mixed>>
     */
    private function buildLineItems(Transaction $transaction, string $currency, string $referenceId): array
    {
        $productMetadata = ['transaction_uuid' => $referenceId];

        $items = [[
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $this->unitAmount((float) $transaction->amount, $currency),
                'product_data' => [
                    'name' => $transaction->pledge_uuid !== null ? 'Pledge payment' : 'Donation',
                    'metadata' => $productMetadata,
                ],
            ],
        ]];

        if ($transaction->hasServiceFee()) {
            $items[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $this->unitAmount((float) $transaction->fee_amount, $currency),
                    'product_data' => [
                        'name' => 'Service fee',
                        'metadata' => $productMetadata,
                    ],
                ],
            ];
        }

        return $items;
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

    private function isMissingCustomer(InvalidRequestException $exception): bool
    {
        return $exception->getStripeCode() === 'resource_missing'
            && $exception->getStripeParam() === 'customer';
    }

    private function isUnsupportedCheckoutAmount(InvalidRequestException $exception): bool
    {
        return in_array($exception->getStripeCode(), ['amount_too_small', 'amount_too_large'], true);
    }
}
