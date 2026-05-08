<?php

namespace App\Enums;

enum EmailDesignTemplate: string
{
    case CLASSIC = 'classic';
    case BENTO = 'bento';
    case CORE = 'core';
    case MINIMAL = 'minimal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
