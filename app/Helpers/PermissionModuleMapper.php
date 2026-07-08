<?php

namespace App\Helpers;

use App\Enums\ePermission;
use App\Enums\ModuleEnums;

final class PermissionModuleMapper
{
    /**
     * @return list<string>
     */
    public static function orderedModuleKeys(): array
    {
        return [
            'user_management',
            'audit_trail',
        ];
    }

    public static function moduleKeyForPermissionName(string $name): string
    {
        $prefix = explode('.', $name, 2)[0] ?? '';

        return match ($prefix) {
            'roles', 'admins' => 'user_management',
            'audit_trail' => 'audit_trail',
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
