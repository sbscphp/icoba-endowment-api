<?php

namespace App\Support;

use App\Enums\PaymentGateway;

/**
 * Decides whether a pledge/transaction being created is test or live data (is_test column).
 * See config/endowment.php "payments" for the PAYMENTS_TEST_MODE override.
 */
final class PaymentMode
{
    public static function isTest(?string $gateway = null): bool
    {
        $forced = config('endowment.payments.test_mode');
        if ($forced !== null) {
            return (bool) $forced;
        }

        if (! app()->environment('production')) {
            return true;
        }

        return self::gatewayUsesTestCredentials($gateway);
    }

    public static function gatewayUsesTestCredentials(?string $gateway): bool
    {
        return match ($gateway) {
            PaymentGateway::Stripe->value => self::isTestKey(config('services.stripe.secret')),
            PaymentGateway::Paystack->value => self::isTestKey(config('services.paystack.secret')),
            PaymentGateway::Fcmb->value => self::isFcmbTestHost(config('services.fcmb.base_url')),
            default => false,
        };
    }

    private static function isTestKey(mixed $secret): bool
    {
        return is_string($secret) && preg_match('/^(sk|rk)_test_/', trim($secret)) === 1;
    }

    private static function isFcmbTestHost(mixed $baseUrl): bool
    {
        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            return false;
        }

        $host = strtolower((string) parse_url(trim($baseUrl), PHP_URL_HOST));

        return $host !== '' && in_array($host, array_map('strtolower', (array) config('endowment.payments.fcmb_test_hosts', [])), true);
    }
}
