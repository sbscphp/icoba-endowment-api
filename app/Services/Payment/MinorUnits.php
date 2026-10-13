<?php

namespace App\Services\Payment;

/**
 * Minor-unit (kobo / cents) conversions shared by Stripe and Paystack.
 */
final class MinorUnits
{
    /** @var list<string> */
    private const ZERO_DECIMAL = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    /** @var list<string> */
    private const THREE_DECIMAL = ['bhd', 'jod', 'kwd', 'omr', 'tnd'];

    public static function toMinor(float $amount, string $currency): int
    {
        return (int) round($amount * self::factor($currency));
    }

    public static function toMajor(int|float|string|null $minor, string $currency): ?float
    {
        if ($minor === null || $minor === '' || ! is_numeric($minor)) {
            return null;
        }

        return round(((float) $minor) / self::factor($currency), 2);
    }

    private static function factor(string $currency): int
    {
        $lower = strtolower(trim($currency));
        if (in_array($lower, self::THREE_DECIMAL, true)) {
            return 1000;
        }
        if (in_array($lower, self::ZERO_DECIMAL, true)) {
            return 1;
        }

        return 100;
    }
}
