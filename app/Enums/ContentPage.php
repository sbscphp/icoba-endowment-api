<?php

namespace App\Enums;

enum ContentPage: string
{
    case HERO_SLIDER = 'hero_slider';

    public function label(): string
    {
        return match ($this) {
            self::HERO_SLIDER => 'Hero Slider',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
