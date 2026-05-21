<?php

namespace App\Enums;

enum PledgePaymentPlanType: string
{
    case ONE_TIME_FUTURE = 'one_time_future';
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case CUSTOM = 'custom';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
