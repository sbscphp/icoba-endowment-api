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
}
