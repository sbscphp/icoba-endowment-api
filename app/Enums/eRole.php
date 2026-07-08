<?php

namespace App\Enums;

enum eRole: string
{
    case ADMIN = 'Admin';
    case CUSTOMER = 'Customer';
    case SUPER_ADMIN = 'Super Admin';

    /**
     * Role names synced by the roles/permissions database seeder.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
