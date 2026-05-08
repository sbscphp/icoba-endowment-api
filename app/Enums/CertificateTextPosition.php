<?php

namespace App\Enums;

enum CertificateTextPosition: string
{
    case LEFT = 'left';
    case CENTER = 'center';
    case RIGHT = 'right';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
