<?php

namespace App\Enums;

/**
 * Canonical admin module keys for permissions UI and audit logging ({@see PermissionModuleMapper}).
 */
enum ModuleEnums: string
{
    /** Default when audit context has no module. */
    case guest = 'guest';

    case dashboard = 'dashboard';
    case campaign_management = 'campaign_management';
    case transactions = 'transactions';
    case tier_configuration = 'tier_configuration';
    case reconciliation = 'reconciliation';
    case issued_certificate = 'issued_certificate';
    case certificate_template = 'certificate_template';
    case user_management = 'user_management';
    case content_management = 'content_management';
    case reports = 'reports';
    case email_campaigns = 'email_campaigns';
    case audit_trail = 'audit_trail';
    case notifications = 'notifications';
    case settings = 'settings';

    /** Admin sign-in and session (not tied to CRUD permissions map). */
    case authentication = 'authentication';

    public function label(): string
    {
        return match ($this) {
            self::guest => 'Guest',
            self::dashboard => 'Dashboard',
            self::campaign_management => 'Campaign management',
            self::transactions => 'Transactions',
            self::tier_configuration => 'Tier configuration',
            self::reconciliation => 'Reconciliation',
            self::issued_certificate => 'Issued certificate',
            self::certificate_template => 'Certificate template',
            self::user_management => 'User management',
            self::content_management => 'Content management',
            self::reports => 'Reports',
            self::email_campaigns => 'Email campaigns',
            self::audit_trail => 'Audit trail',
            self::notifications => 'Notifications',
            self::settings => 'Settings',
            self::authentication => 'Authentication',
        };
    }

    /**
     * All backed values (for validation / filters).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
