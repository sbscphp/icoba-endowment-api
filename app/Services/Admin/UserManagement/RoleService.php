<?php

namespace App\Services\Admin\UserManagement;

use App\Enums\eRole;
use App\Exceptions\ApiException;
use App\Helpers\PermissionModuleMapper;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

class RoleService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): Role
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

        return $role->fresh() ?? $role;
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
        $this->applyDateRange($query, $validated, 'created_at');

        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->count(),
            'inactive' => (clone $query)->where('is_active', false)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(array $validated): LengthAwarePaginator
    {
        $sortBy = (string) ($validated['sort_by'] ?? 'updated_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        $query = Role::query()
            ->where('guard_name', 'api')
            ->where('name', '!=', eRole::CUSTOMER->value)
            ->withCount([
                'admins as users_count' => fn (Builder $builder) => $builder->where('admins.is_active', true),
            ]);

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

        return $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);
    }

    public function findRole(string $roleId): Role
    {
        return $this->resolveRole($roleId);
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
    public function update(string $roleId, array $payload): Role
    {
        $role = $this->resolveRole($roleId);
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

        return $role->fresh() ?? $role;
    }

    public function toggleActiveStatus(string $roleId): Role
    {
        $role = $this->resolveRole($roleId);
        $isActive = ! ((bool) $role->is_active);

        if (! $isActive && $role->admins()->exists()) {
            throw new ApiException('Role cannot be deactivated because it is assigned to one or more admin users.', 422);
        }

        $role->forceFill(['is_active' => $isActive])->save();

        return $role->fresh() ?? $role;
    }

    /**
     * @return array{admin_users_count:int}
     */
    public function delete(string $roleId): array
    {
        $role = $this->resolveRole($roleId);
        $adminUsersCount = $role->admins()->count();

        if ($adminUsersCount > 0) {
            return ['admin_users_count' => $adminUsersCount];
        }

        $role->delete();

        return ['admin_users_count' => 0];
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

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyDateRange(Builder $query, array $validated, string $column): void
    {
        $startDate = ! empty($validated['start_date']) ? Carbon::parse((string) $validated['start_date'])->startOfDay() : null;
        $endDate = ! empty($validated['end_date']) ? Carbon::parse((string) $validated['end_date'])->endOfDay() : null;

        if ($startDate !== null) {
            $query->where($column, '>=', $startDate);
        }

        if ($endDate !== null) {
            $query->where($column, '<=', $endDate);
        }
    }
}
