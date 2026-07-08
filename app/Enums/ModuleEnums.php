<?php

namespace App\Enums;

/**
 * Canonical admin module keys for permissions UI and audit logging ({@see PermissionModuleMapper}).
 */
enum ModuleEnums: string
{
    /** Default when audit context has no module. */
    case guest = 'guest';

    case user_management = 'user_management';
    case audit_trail = 'audit_trail';

    /** Personal account settings (profile, password, 2FA). */
    case settings = 'settings';

    /** Admin/customer sign-in and session events (not tied to CRUD permissions map). */
    case authentication = 'authentication';

    public function label(): string
    {
        return match ($this) {
            self::guest => 'Guest',
            self::user_management => 'User management',
            self::audit_trail => 'Audit trail',
            self::settings => 'Settings',
            self::authentication => 'Authentication',
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
