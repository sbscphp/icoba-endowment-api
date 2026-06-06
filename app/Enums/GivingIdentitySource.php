<?php

namespace App\Enums;

enum GivingIdentitySource: string
{
    case GUEST_CHECKOUT = 'guest_checkout';
    case REGISTRATION = 'registration';
    case ADMIN = 'admin';
    case RECONCILIATION = 'reconciliation';
    case PLEDGE = 'pledge';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
