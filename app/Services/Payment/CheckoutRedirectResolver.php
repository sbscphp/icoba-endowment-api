<?php

namespace App\Services\Payment;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * Resolves post-checkout redirect targets for hosted gateways.
 *
 * Precedence: explicit request URL → request frontend_url + path → gateway env override
 * (STRIPE_SUCCESS_URL, PAYSTACK_CALLBACK_URL, FCMB_SUCCESS_URL, …) → FRONTEND_URL + path.
 */
final class CheckoutRedirectResolver
{
    /**
     * @return array{success_url: string, failed_url: string}
     */
    public function resolve(
        string $gateway,
        ?string $successUrl,
        ?string $failedUrl,
        ?string $legacyCancelUrl,
        ?string $frontendUrl,
    ): array {
        return [
            'success_url' => $this->resolveSuccessUrl($gateway, $successUrl, $frontendUrl),
            'failed_url' => $this->resolveFailedUrl($gateway, $failedUrl, $legacyCancelUrl, $frontendUrl),
        ];
    }

    public function persistOnTransaction(Transaction $transaction, string $successUrl, string $failedUrl): void
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $metadata['checkout_redirects'] = [
            'success_url' => $successUrl,
            'failed_url' => $failedUrl,
        ];

        $transaction->forceFill(['metadata' => $metadata])->save();
    }

    public function redirectForPaymentStatus(Transaction $transaction, string $paymentStatus): ?string
    {
        $redirects = data_get($transaction->metadata, 'checkout_redirects');
        if (! is_array($redirects)) {
            return null;
        }

        if (in_array($paymentStatus, ['paid', 'complete', 'no_payment_required'], true)) {
            $url = $redirects['success_url'] ?? null;

            return is_string($url) && $url !== '' ? $url : null;
        }

        if (in_array($paymentStatus, ['unpaid', 'expired', 'failed'], true)) {
            $url = $redirects['failed_url'] ?? null;

            return is_string($url) && $url !== '' ? $url : null;
        }

        return null;
    }

    private function resolveSuccessUrl(string $gateway, ?string $fromRequest, ?string $frontendUrl): string
    {
        $base = $fromRequest
            ?? $this->urlFromFrontendBase($frontendUrl, '/donate/success')
            ?? $this->configuredUrl($gateway, 'success_url')
            ?? $this->configuredUrl($gateway, 'callback_url')
            ?? $this->urlFromFrontendBase($this->appFrontendUrl(), '/donate/success');

        if (! is_string($base) || trim($base) === '') {
            Log::warning("{$gateway} checkout: no success redirect URL could be resolved; set FRONTEND_URL.");

            $base = '/donate/success';
        }

        if ($gateway === 'stripe') {
            return $this->ensureStripeCheckoutSessionPlaceholder($base);
        }

        return $this->sanitizePaystackCallbackUrl($base);
    }

    private function resolveFailedUrl(
        string $gateway,
        ?string $fromRequest,
        ?string $legacyCancelUrl,
        ?string $frontendUrl,
    ): string {
        $base = $fromRequest
            ?? $legacyCancelUrl
            ?? $this->urlFromFrontendBase($frontendUrl, '/donate/failed')
            ?? $this->configuredUrl($gateway, 'failed_url')
            ?? $this->configuredUrl($gateway, 'cancel_url')
            ?? $this->urlFromFrontendBase($this->appFrontendUrl(), '/donate/failed');

        if (! is_string($base) || trim($base) === '') {
            Log::warning("{$gateway} checkout: no failed redirect URL could be resolved; set FRONTEND_URL.");

            $base = '/donate/failed';
        }

        return $base;
    }

    private function appFrontendUrl(): ?string
    {
        $url = config('app.frontend_url');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return $url;
    }

    private function urlFromFrontendBase(?string $frontendUrl, string $path): ?string
    {
        if (! is_string($frontendUrl) || trim($frontendUrl) === '') {
            return null;
        }

        return rtrim($frontendUrl, '/').$path;
    }

    private function configuredUrl(string $gateway, string $key): ?string
    {
        $url = config("services.{$gateway}.{$key}");

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return $url;
    }

    /**
     * Stripe replaces the literal `{CHECKOUT_SESSION_ID}` after payment.
     */
    private function ensureStripeCheckoutSessionPlaceholder(string $url): string
    {
        if (str_contains($url, '{CHECKOUT_SESSION_ID}')) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
    }

    /**
     * Paystack appends ?trxref= and &reference= on redirect — unlike Stripe, it does not
     * replace {CHECKOUT_SESSION_ID}. Strip Stripe-style placeholders from shared success URLs.
     */
    private function sanitizePaystackCallbackUrl(string $url): string
    {
        $url = str_replace('{CHECKOUT_SESSION_ID}', '', $url);
        $url = preg_replace('/([?&])(session_id|reference)=[^&]*&?/', '$1', $url) ?? $url;

        return rtrim($url, '?&');
    }
}
