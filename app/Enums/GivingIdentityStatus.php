<?php

namespace App\Enums;

enum GivingIdentityStatus: string
{
    case UNVERIFIED = 'unverified';
    case ACTIVE = 'active';
    case CONFLICT = 'conflict';
    case MERGED = 'merged';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
