<?php

namespace App\Enums;

enum CampaignCategory: string
{
    case INFRASTRUCTURAL_DEVELOPMENT = 'infrastructural_development';
    case EDUCATIONAL_FUND = 'educational_fund';
    case WELFARE = 'welfare';
    case EVENTS = 'events';
    case OTHERS = 'others';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::INFRASTRUCTURAL_DEVELOPMENT => 'Infrastructural development',
            self::EDUCATIONAL_FUND => 'Educational fund',
            self::WELFARE => 'Welfare',
            self::EVENTS => 'Events',
            self::OTHERS => 'Others',
        };
    }
}
