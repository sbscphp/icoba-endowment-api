<?php

namespace App\Helpers;

use App\Enums\ePermission;
use App\Enums\ModuleEnums;

final class PermissionModuleMapper
{
    /**
     * Ordered module keys used when returning grouped permissions.
     *
     * @return list<string>
     */
    public static function orderedModuleKeys(): array
    {
        return [
            'dashboard',
            'campaign_management',
            'transactions',
            'pledges',
            'tier_configuration',
            'reconciliation',
            'issued_certificate',
            'certificate_template',
            'user_management',
            'content_management',
            'contact_submissions',
            'reports',
            'email_campaigns',
            'audit_trail',
            'notifications',
            'settings',
        ];
    }

    public static function moduleKeyForPermissionName(string $name): string
    {
        $prefix = explode('.', $name, 2)[0] ?? '';

        return match ($prefix) {
            'dashboard' => 'dashboard',
            'campaigns' => 'campaign_management',
            'transactions' => 'transactions',
            'pledges' => 'pledges',
            'tier_configuration' => 'tier_configuration',
            'reconciliation' => 'reconciliation',
            'issued_certificates' => 'issued_certificate',
            'certificate_templates' => 'certificate_template',
            'roles', 'admins' => 'user_management',
            'content_management' => 'content_management',
            'contact_submissions' => 'contact_submissions',
            'reports' => 'reports',
            'email_campaigns' => 'email_campaigns',
            'audit_trail' => 'audit_trail',
            'notifications' => 'notifications',
            'settings' => 'settings',
            default => 'other',
        };
    }

    /**
     * @return list<array{key: string, label: string, permissions: list<array{name: string}>}>
     */
    public static function groupedApiPermissions(): array
    {
        $buckets = [];

        foreach (ePermission::cases() as $perm) {
            $key = self::moduleKeyForPermissionName($perm->value);
            if (! isset($buckets[$key])) {
                $buckets[$key] = [];
            }
            $buckets[$key][] = ['name' => $perm->value];
        }

        $order = array_merge(self::orderedModuleKeys(), ['other']);
        $out = [];

        foreach ($order as $key) {
            if (empty($buckets[$key])) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $key === 'other'
                    ? 'Other'
                    : (ModuleEnums::tryFrom($key)?->label() ?? $key),
                'permissions' => $buckets[$key],
            ];
            unset($buckets[$key]);
        }

        foreach ($buckets as $key => $perms) {
            if ($perms === []) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => ModuleEnums::tryFrom($key)?->label() ?? $key,
                'permissions' => $perms,
            ];
        }

        return $out;
    }

    /**
     * Same module shape as {@see groupedApiPermissions()}, but only permission names
     * present in $permissionNames. Modules with no matching permissions are omitted.
     *
     * @param  list<string>  $permissionNames
     * @return list<array{key: string, label: string, permissions: list<array{name: string}>}>
     */
    public static function groupedApiPermissionsForNames(array $permissionNames): array
    {
        if ($permissionNames === []) {
            return [];
        }

        $nameSet = array_flip($permissionNames);

        $out = [];
        foreach (self::groupedApiPermissions() as $module) {
            $perms = array_values(array_filter(
                $module['permissions'],
                fn (array $p): bool => isset($nameSet[$p['name']])
            ));
            if ($perms !== []) {
                $out[] = [
                    'key' => $module['key'],
                    'label' => $module['label'],
                    'permissions' => $perms,
                ];
            }
        }

        return $out;
    }
}
