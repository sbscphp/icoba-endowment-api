<?php

namespace App\Enums;

enum PledgeStatus: string
{
    case ACTIVE = 'active';
    case FULFILLED = 'fulfilled';
    case CANCELLED = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
