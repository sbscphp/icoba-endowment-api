<?php

namespace App\Enums;

enum BulkEmailAudience: string
{
    case MEMBERS_ONLY = 'members_only';
    case CORPORATE = 'corporate';
    case ANONYMOUS_DONORS = 'anonymous_donors';
    case ALL_DONORS = 'all_donors';
    case FRIENDS_OF_ICOBA = 'friends_of_icoba';
    case RELATIVES_OF_ICOBA = 'relatives_of_icoba';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::MEMBERS_ONLY => 'Members only',
            self::CORPORATE => 'Corporate donors',
            self::ANONYMOUS_DONORS => 'Anonymous donors',
            self::ALL_DONORS => 'All donors',
            self::FRIENDS_OF_ICOBA => 'Friends of ICOBA',
            self::RELATIVES_OF_ICOBA => 'Relatives of ICOBA',
        };
    }
}
