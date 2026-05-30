<?php

namespace App\Enums;

enum Currency: string
{
    case NGN = 'NGN';
    case USD = 'USD';
    case GBP = 'GBP';
    case EUR = 'EUR';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Currencies fetched from the live FX provider (NGN per 1 unit). */
    public static function fxFetchable(): array
    {
        return [self::USD, self::GBP, self::EUR];
    }

    public function symbol(): string
    {
        return match ($this) {
            self::NGN => '₦',
            self::USD => '$',
            self::GBP => '£',
            self::EUR => '€',
        };
    }

    /**
     * Fallback NGN per 1 unit when no exchange_rates row exists for the requested date.
     */
    public function referenceNairaRatePerUnit(): float
    {
        return match ($this) {
            self::NGN => 1.0,
            self::USD => 1500.0,
            self::GBP => 1900.0,
            self::EUR => 1650.0,
        };
    }
}
