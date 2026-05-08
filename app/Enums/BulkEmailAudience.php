<?php

namespace App\Enums;

enum BulkEmailAudience: string
{
    case MEMBERS_ONLY = 'members_only';
    case CORPORATE = 'corporate';
    case ANONYMOUS_DONORS = 'anonymous_donors';
    case ALL_DONORS = 'all_donors';
    case FRIENDS_OF_ICOBA = 'friends_of_icoba';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
