<?php

namespace App\Enums;

enum PublicCampaignVisibilityFilter: string
{
    case ALL = 'all';
    case ACTIVE = 'active';
    case CLOSED = 'closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
