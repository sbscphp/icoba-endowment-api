<?php

namespace App\Services\Admin\UserManagement;

use App\Enums\AuditActionEnum;
use App\Enums\eRole;
use App\Enums\ModuleEnums;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Helpers\PermissionModuleMapper;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\Admin;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class RoleService
{
    private const MAX_EXPORT_ROWS = 5000;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, Admin $actor, Request $request): Role
    {
        $role = Role::query()->create([
            'name' => (string) $payload['name'],
            'guard_name' => 'api',
            'description' => $payload['description'] ?? null,
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);

        $permissions = $payload['permissions'] ?? null;
        if (is_array($permissions)) {
            $role->syncPermissions($permissions);
        }

        $role = $role->fresh() ?? $role;

        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::ROLE_CREATED,
            $request,
            $actor->uuid,
            [
                'role_uuid' => $role->uuid,
                'role_name' => $role->name,
                'permissions_count' => is_array($permissions) ? count($permissions) : 0,
            ],
            'Role created.',
            Role::class,
            $role->uuid,
            ModuleEnums::user_management,
            200,
        );

        return $role;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{total:int,active:int,inactive:int}
     */
    public function stats(array $validated): array
    {
        $query = Role::query()
            ->where('guard_name', 'api')
            ->where('name', '!=', eRole::CUSTOMER->value);
        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        return array_merge(ListingFilterRules::periodMeta($validated), [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->count(),
            'inactive' => (clone $query)->where('is_active', false)->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(array $validated): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        return $this->baseListQuery($validated)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: Collection<int, Role>, 1: bool}
     */
    public function exportCollection(array $validated): array
    {
        $query = $this->baseListQuery($validated);
        $total = (clone $query)->count();
        $truncated = $total > self::MAX_EXPORT_ROWS;
        /** @var Collection<int, Role> $rows */
        $rows = $query->limit(self::MAX_EXPORT_ROWS)->get();

        return [$rows, $truncated];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $sortBy = (string) ($validated['sort_by'] ?? 'updated_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = Role::query()
            ->where('guard_name', 'api')
            ->where('name', '!=', eRole::CUSTOMER->value)
            ->withCount([
                'admins as users_count' => fn (Builder $builder) => $builder->where('admins.is_active', true),
            ]);
        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('uuid', 'like', '%'.$search.'%');
            });
        }

        $status = data_get($validated, 'filters.status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if (! in_array($sortBy, ['id', 'name', 'users_count', 'updated_at', 'is_active'], true)) {
            $sortBy = 'updated_at';
        }

        return $query->orderBy($sortBy, $sortDirection);
    }

    public function findRole(string $roleId): Role
    {
        return $this->resolveRole($roleId);
    }

    /**
     * @return Collection<int, Role>
     */
    public function listWithPermissions(): Collection
    {
        return Role::query()
            ->where('guard_name', 'api')
            ->where('name', '!=', eRole::CUSTOMER->value)
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{
     *     permissions: list<string>,
     *     permissions_by_module: list<array{key: string, label: string, permissions: list<array{name: string}>}>
     * }
     */
    public function listAllPermissions(): array
    {
        $permissionsByModule = PermissionModuleMapper::groupedApiPermissions();
        $permissions = [];

        foreach ($permissionsByModule as $module) {
            foreach ($module['permissions'] as $permission) {
                $permissions[] = $permission['name'];
            }
        }

        return [
            'permissions' => $permissions,
            'permissions_by_module' => $permissionsByModule,
        ];
    }

    /**
     * @return Collection<int, Role>
     */
    public function dropdown(string $status = 'active'): Collection
    {
        $query = Role::query()
            ->where('guard_name', 'api')
            ->where('name', '!=', eRole::CUSTOMER->value);

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        return $query
            ->orderBy('name')
            ->get(['uuid', 'name', 'is_active']);
    }

    /**
     * @return array<string, mixed>
     */
    public function view(string $roleId): array
    {
        $role = $this->resolveRole($roleId);
        $role->load('permissions:id,name');
        $permissionNames = $role->permissions->pluck('name')->values()->all();

        return [
            'role_id' => $role->uuid ?? (string) $role->id,
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'status' => $role->is_active ? 'active' : 'inactive',
            'permissions' => $permissionNames,
            'permissions_by_module' => PermissionModuleMapper::groupedApiPermissionsForNames($permissionNames),
            'updated_at' => $role->updated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(string $roleId, array $payload, Admin $actor, Request $request): Role
    {
        $role = $this->resolveRole($roleId);
        $role->loadMissing('permissions:id,name');
        $previous = [
            'name' => $role->name,
            'description' => $role->description,
            'is_active' => (bool) $role->is_active,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];

        $permissions = $payload['permissions'] ?? null;
        unset($payload['permissions']);

        if (
            array_key_exists('is_active', $payload)
            && (bool) $role->is_active === true
            && (bool) $payload['is_active'] === false
            && $role->admins()->exists()
        ) {
            throw new ApiException('Role cannot be deactivated because it is assigned to one or more admin users.', 422);
        }

        if ($payload !== []) {
            $role->fill($payload)->save();
        }

        if (is_array($permissions)) {
            $role->syncPermissions($permissions);
        }

        $role = $role->fresh() ?? $role;
        $role->loadMissing('permissions:id,name');

        $changedFields = [];
        foreach (['name', 'description', 'is_active'] as $field) {
            if (array_key_exists($field, $payload) && $previous[$field] !== $role->{$field}) {
                $changedFields[] = $field;
            }
        }
        if (is_array($permissions)) {
            $newPermissions = $role->permissions->pluck('name')->sort()->values()->all();
            if ($previous['permissions'] !== $newPermissions) {
                $changedFields[] = 'permissions';
            }
        }

        if ($changedFields !== []) {
            $metadata = [
                'role_uuid' => $role->uuid,
                'role_name' => $role->name,
                'fields' => $changedFields,
            ];
            if (in_array('permissions', $changedFields, true)) {
                $metadata['previous_permissions_count'] = count($previous['permissions']);
                $metadata['new_permissions_count'] = $role->permissions->count();
            }

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::ROLE_UPDATED,
                $request,
                $actor->uuid,
                $metadata,
                'Role updated.',
                Role::class,
                $role->uuid,
                ModuleEnums::user_management,
                200,
            );
        }

        return $role;
    }

    public function toggleActiveStatus(string $roleId, Admin $actor, Request $request): Role
    {
        $role = $this->resolveRole($roleId);
        $previousStatus = (bool) $role->is_active;
        $isActive = ! $previousStatus;

        if (! $isActive && $role->admins()->exists()) {
            throw new ApiException('Role cannot be deactivated because it is assigned to one or more admin users.', 422);
        }

        $role->forceFill(['is_active' => $isActive])->save();

        $role = $role->fresh() ?? $role;

        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::ROLE_STATUS_TOGGLED,
            $request,
            $actor->uuid,
            [
                'role_uuid' => $role->uuid,
                'role_name' => $role->name,
                'previous_status' => $previousStatus ? 'active' : 'inactive',
                'new_status' => $isActive ? 'active' : 'inactive',
            ],
            $isActive ? 'Role activated.' : 'Role deactivated.',
            Role::class,
            $role->uuid,
            ModuleEnums::user_management,
            200,
        );

        return $role;
    }

    /**
     * @return array{admin_users_count:int, uuid?:string, name?:string}
     */
    public function delete(string $roleId, Admin $actor, Request $request): array
    {
        $role = $this->resolveRole($roleId);
        $adminUsersCount = $role->admins()->count();

        if ($adminUsersCount > 0) {
            return ['admin_users_count' => $adminUsersCount];
        }

        $roleUuid = $role->uuid;
        $roleName = $role->name;
        $role->delete();

        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::ROLE_DELETED,
            $request,
            $actor->uuid,
            ['role_uuid' => $roleUuid, 'role_name' => $roleName],
            'Role deleted.',
            Role::class,
            $roleUuid,
            ModuleEnums::user_management,
            200,
        );

        return ['admin_users_count' => 0, 'uuid' => $roleUuid, 'name' => $roleName];
    }

    private function resolveRole(string $roleId): Role
    {
        $role = Role::query()
            ->where('guard_name', 'api')
            ->where(function (Builder $builder) use ($roleId): void {
                $builder->where('uuid', $roleId);
                if (is_numeric($roleId)) {
                    $builder->orWhere('id', (int) $roleId);
                }
            })
            ->first();

        if ($role === null) {
            throw (new ModelNotFoundException)->setModel(Role::class, [$roleId]);
        }

        return $role;
    }

}
