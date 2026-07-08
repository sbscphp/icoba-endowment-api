<?php

namespace App\Enums;

enum ePermission: string
{
    // User management (roles + admins)
    case ROLES_CREATE = 'roles.create';
    case ROLES_READ = 'roles.read';
    case ROLES_UPDATE = 'roles.update';
    case ROLES_DELETE = 'roles.delete';
    case ADMINS_CREATE = 'admins.create';
    case ADMINS_READ = 'admins.read';
    case ADMINS_UPDATE = 'admins.update';
    case ADMINS_DELETE = 'admins.delete';

    // Audit trail (read API — feature/audit-trail)
    case AUDIT_TRAIL_READ = 'audit_trail.read';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
