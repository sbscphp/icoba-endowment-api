<?php

namespace App\Services\Payment;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final class StripeCheckoutService
{
    /** @var list<string> */
    private const ZERO_DECIMAL = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    /** @var list<string> */
    private const THREE_DECIMAL = ['bhd', 'jod', 'kwd', 'omr', 'tnd'];

    private StripeClient $stripe;

    public function __construct()
    {
        $secret = config('services.stripe.secret');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Stripe is not configured (STRIPE_SECRET).');
        }
        $this->stripe = new StripeClient($secret);
    }

    /**
     * Create a Checkout Session and persist its id on the pending transaction.
     *
     * @return array{session_id: string, url: string}
     *
     * @throws ApiErrorException
     */
    public function createCheckoutSession(
        Transaction $transaction,
        ?User $donorUser,
        ?string $successUrl,
        ?string $cancelUrl,
    ): array {
        $currency = strtolower((string) $transaction->currency);
        $unitAmount = $this->unitAmount((float) $transaction->amount, $currency);

        $success = $this->resolveSuccessUrl($successUrl);
        $cancel = $this->resolveCancelUrl($cancelUrl);

        $params = [
            'mode' => 'payment',
            'client_reference_id' => $transaction->uuid,
            'success_url' => $success,
            'cancel_url' => $cancel,
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

        $session = $this->stripe->checkout->sessions->create($params);

        $transaction->forceFill([
            'gateway' => 'stripe',
            'gateway_reference' => $session->id,
        ])->save();

        return [
            'session_id' => $session->id,
            'url' => (string) $session->url,
        ];
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

    private function resolveSuccessUrl(?string $fromRequest): string
    {
        $base = $fromRequest ?? config('services.stripe.success_url') ?? config('app.frontend_url').'/donate/success';

        if (! is_string($base) || trim($base) === '') {
            Log::warning('Stripe checkout: falling back to APP_URL for success URL.');

            $base = rtrim((string) config('app.url'), '/').'/donate/success';
        }

        return $this->ensureCheckoutSessionPlaceholder($base);
    }

    private function resolveCancelUrl(?string $fromRequest): string
    {
        $base = $fromRequest ?? config('services.stripe.cancel_url') ?? config('app.frontend_url').'/donate';

        if (! is_string($base) || trim($base) === '') {
            $base = rtrim((string) config('app.url'), '/').'/donate';
        }

        return $base;
    }

    /**
     * Stripe replaces the literal `{CHECKOUT_SESSION_ID}` after payment.
     */
    private function ensureCheckoutSessionPlaceholder(string $url): string
    {
        if (str_contains($url, '{CHECKOUT_SESSION_ID}')) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
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
}
