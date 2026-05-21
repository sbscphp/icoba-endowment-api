<?php

namespace App\Enums;

enum ePermission: string
{
    // 1. Dashboard
    case DASHBOARD_READ = 'dashboard.read';

    // 2. Campaign management
    case CAMPAIGNS_READ = 'campaigns.read';
    case CAMPAIGNS_CREATE = 'campaigns.create';
    case CAMPAIGNS_UPDATE = 'campaigns.update';
    case CAMPAIGNS_DELETE = 'campaigns.delete';
    case CAMPAIGNS_PUBLISH = 'campaigns.publish';

    // 3. Transactions
    case TRANSACTIONS_READ = 'transactions.read';
    case TRANSACTIONS_REVERSE = 'transactions.reverse';
    case TRANSACTIONS_RETRY = 'transactions.retry';
    case TRANSACTIONS_EXPORT = 'transactions.export';

    // 4. Tier configuration
    case TIER_CONFIGURATION_READ = 'tier_configuration.read';
    case TIER_CONFIGURATION_CREATE = 'tier_configuration.create';
    case TIER_CONFIGURATION_UPDATE = 'tier_configuration.update';
    case TIER_CONFIGURATION_DELETE = 'tier_configuration.delete';

    // 5. Pledges
    case PLEDGES_READ = 'pledges.read';
    case PLEDGES_CREATE = 'pledges.create';

    // 6. Reconciliation
    case RECONCILIATION_READ = 'reconciliation.read';
    case RECONCILIATION_UPDATE = 'reconciliation.update';

    // 6. Issued certificate
    case ISSUED_CERTIFICATES_READ = 'issued_certificates.read';
    case ISSUED_CERTIFICATES_ISSUE = 'issued_certificates.issue';
    case ISSUED_CERTIFICATES_REVOKE = 'issued_certificates.revoke';
    case ISSUED_CERTIFICATES_EXPORT = 'issued_certificates.export';

    // 7. Certificate template
    case CERTIFICATE_TEMPLATES_READ = 'certificate_templates.read';
    case CERTIFICATE_TEMPLATES_CREATE = 'certificate_templates.create';
    case CERTIFICATE_TEMPLATES_UPDATE = 'certificate_templates.update';
    case CERTIFICATE_TEMPLATES_DELETE = 'certificate_templates.delete';

    // 8. User management (roles + admins)
    case ROLES_CREATE = 'roles.create';
    case ROLES_READ = 'roles.read';
    case ROLES_UPDATE = 'roles.update';
    case ROLES_DELETE = 'roles.delete';
    case ADMINS_CREATE = 'admins.create';
    case ADMINS_READ = 'admins.read';
    case ADMINS_UPDATE = 'admins.update';
    case ADMINS_DELETE = 'admins.delete';

    // 9. Content management
    case CONTENT_MANAGEMENT_READ = 'content_management.read';
    case CONTENT_MANAGEMENT_CREATE = 'content_management.create';
    case CONTENT_MANAGEMENT_UPDATE = 'content_management.update';
    case CONTENT_MANAGEMENT_DELETE = 'content_management.delete';

    // 10. Reports
    case REPORTS_READ = 'reports.read';
    case REPORTS_EXPORT = 'reports.export';

    // 11. Email campaigns
    case EMAIL_CAMPAIGNS_READ = 'email_campaigns.read';
    case EMAIL_CAMPAIGNS_CREATE = 'email_campaigns.create';
    case EMAIL_CAMPAIGNS_UPDATE = 'email_campaigns.update';
    case EMAIL_CAMPAIGNS_DELETE = 'email_campaigns.delete';
    case EMAIL_CAMPAIGNS_SEND = 'email_campaigns.send';

    // 12. Audit trail
    case AUDIT_TRAIL_READ = 'audit_trail.read';

    // 13. Notifications
    case NOTIFICATIONS_READ = 'notifications.read';
    case NOTIFICATIONS_CREATE = 'notifications.create';
    case NOTIFICATIONS_UPDATE = 'notifications.update';
    case NOTIFICATIONS_DELETE = 'notifications.delete';

    // 14. Settings
    case SETTINGS_READ = 'settings.read';
    case SETTINGS_UPDATE = 'settings.update';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
