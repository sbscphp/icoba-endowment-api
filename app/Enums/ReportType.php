<?php

namespace App\Enums;

enum ReportType: string
{
    case TRANSACTIONS = 'transactions';
    case TIER_CONFIGURATIONS = 'tier_configurations';
    case ADMIN_USERS = 'admin_users';
    case ROLES = 'roles';
    case CAMPAIGNS = 'campaigns';
    case EMAIL_CAMPAIGNS = 'email_campaigns';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::TRANSACTIONS => 'Transactions',
            self::TIER_CONFIGURATIONS => 'Tier configurations',
            self::ADMIN_USERS => 'Admin users',
            self::ROLES => 'Roles',
            self::CAMPAIGNS => 'Campaigns',
            self::EMAIL_CAMPAIGNS => 'Email campaigns',
        };
    }
}
