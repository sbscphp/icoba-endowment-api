<?php

namespace App\Enums;

enum eClientType: string
{
    case MOBILE = 'mobile';
    case WEB = 'web';

    /**
     * Values array for validation
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
