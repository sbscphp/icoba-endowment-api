<?php

namespace App\Enums;

enum eRole: string
{
    case ADMIN = 'admin';
    case CUSTOMER = 'customer';
    case SUPER_ADMIN = 'super_admin';

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
