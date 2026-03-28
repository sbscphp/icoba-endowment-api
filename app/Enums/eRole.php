<?php

namespace App\Enums;

enum eRole: string
{
    case ADMIN = 'admin';
    case CUSTOMER = 'customer';
    case SUPER_ADMIN = 'super_admin';
    case PLATFORM_ADMIN = 'platform_officer';
    case SYSTEM_ADMIN = 'system_officer';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
