<?php

namespace App\Enums;

enum Currency: string
{
    case NGN = 'NGN';
    case USD = 'USD';
    case GBP = 'GBP';
    case EUR = 'EUR';
    case GHS = 'GHS';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function symbol(): string
    {
        return match ($this) {
            self::NGN => '₦',
            self::USD => '$',
            self::GBP => '£',
            self::EUR => '€',
            self::GHS => '₵',
        };
    }

    /**
     * Stub reference: NGN per 1 unit of this currency (aligns with historical seed data).
     * Replace with a live FX service when available; pledge capture stores the resolved value at creation time.
     */
    public function referenceNairaRatePerUnit(): float
    {
        return match ($this) {
            self::NGN => 1.0,
            self::USD => 1500.0,
            self::GBP => 1900.0,
            self::EUR => 1650.0,
            self::GHS => 130.0,
        };
    }
}
