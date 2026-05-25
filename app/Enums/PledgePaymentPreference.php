<?php

namespace App\Enums;

enum PledgePaymentPreference: string
{
    case SCHEDULED = 'scheduled';
    case PAY_ALL = 'pay_all';
    case PARTIAL = 'partial';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
