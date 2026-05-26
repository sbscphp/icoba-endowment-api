<?php

namespace App\Enums;

enum PaymentGateway: string
{
    case Stripe = 'stripe';
    case Paystack = 'paystack';
    case Fcmb = 'fcmb';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isImplemented(): bool
    {
        return match ($this) {
            self::Stripe, self::Paystack => true,
            self::Fcmb => false,
        };
    }
}
