<?php

namespace App\Enums;

enum CertificateImageType: string
{
    case IMAGE_RIGHT = 'image_right';
    case BACKGROUND = 'background';
    case IMAGE_LEFT = 'image_left';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
