<?php

namespace App\Services\Admin\UserManagement;

use App\Enums\AuditActionEnum;
use App\Enums\ModuleEnums;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Jobs\SendAdminInviteSetPasswordEmailJob;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdminUserService
{
    private const MAX_EXPORT_ROWS = 5000;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, Admin $actor, Request $request): Admin
    {
        $roleUuid = (string) $payload['role_id'];

        $role = Role::query()
            ->where('guard_name', 'api')
            ->where('uuid', $roleUuid)
            ->firstOrFail();

        $frontendUrl = isset($payload['frontend_url']) && is_string($payload['frontend_url'])
            ? $payload['frontend_url']
            : null;

        $admin = DB::transaction(function () use ($payload, $role): Admin {
            $admin = Admin::query()->create([
                'name' => (string) $payload['name'],
                'email' => (string) $payload['email'],
                // Random placeholder; admin sets real password via emailed invite link.
                'password' => bin2hex(random_bytes(16)),
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'can_login' => (bool) ($payload['can_login'] ?? true),
                'must_reset_password' => true,
            ]);

            $admin->syncRoles([$role->name]);

            return $admin;
        });

        $this->queueInviteResetLink($admin, $frontendUrl);

        $admin = $admin->fresh() ?? $admin;

        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::ADMIN_CREATED,
            $request,
            $actor->uuid,
            [
                'admin_uuid' => $admin->uuid,
                'admin_email' => $admin->email,
                'role_name' => $role->name,
            ],
            'Admin user created.',
            Admin::class,
            $admin->uuid,
            ModuleEnums::user_management,
            200,
        );

        return $admin;
    }

    public function resendInviteResetLink(string $adminId, Admin $actor, Request $request): Admin
    {
        $admin = $this->resolveAdmin($adminId);

        if (! (bool) $admin->must_reset_password) {
            throw new ApiException('Reset link can only be resent for admins pending first-time password setup.', 422);
        }

        $this->queueInviteResetLink($admin);

        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::ADMIN_INVITE_LINK_RESENT,
            $request,
            $actor->uuid,
            ['admin_uuid' => $admin->uuid, 'admin_email' => $admin->email],
            'Admin invite password setup link resent.',
            Admin::class,
            $admin->uuid,
            ModuleEnums::user_management,
            200,
        );

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{total:int,active:int,inactive:int}
     */
    public function stats(array $validated): array
    {
        $query = Admin::query();
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
     * @return array{0: Collection<int, Admin>, 1: bool}
     */
    public function exportCollection(array $validated): array
    {
        $query = $this->baseListQuery($validated);
        $total = (clone $query)->count();
        $truncated = $total > self::MAX_EXPORT_ROWS;
        /** @var Collection<int, Admin> $rows */
        $rows = $query->limit(self::MAX_EXPORT_ROWS)->get();

        return [$rows, $truncated];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = Admin::query()->with('roles:id,name');
        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('uuid', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhereHas('roles', fn (Builder $roleBuilder) => $roleBuilder->where('name', 'like', '%'.$search.'%'));
            });
        }

        $status = data_get($validated, 'filters.status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if (! in_array($sortBy, ['uuid', 'name', 'email', 'last_active_at', 'is_active', 'created_at'], true)) {
            $sortBy = 'created_at';
        }

        return $query->orderBy($sortBy, $sortDirection);
    }

    public function findAdmin(string $adminId): Admin
    {
        return $this->resolveAdmin($adminId);
    }

    /**
     * @return Collection<int, Admin>
     */
    public function dropdown(string $status = 'active'): Collection
    {
        $query = Admin::query();

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        return $query
            ->orderBy('name')
            ->get(['uuid', 'name', 'email', 'is_active']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(string $adminId, array $payload, Admin $actor, Request $request): Admin
    {
        $admin = $this->resolveAdmin($adminId);
        $admin->loadMissing('roles:id,name');
        $previousRoleName = $admin->roles->first()?->name;
        $previous = [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_active' => (bool) $admin->is_active,
            'can_login' => (bool) $admin->can_login,
            'role_name' => $previousRoleName,
        ];

        $roleUuid = $payload['role_id'] ?? null;
        unset($payload['role_id']);

        if ($payload !== []) {
            $admin->fill($payload)->save();
        }

        $newRoleName = $previousRoleName;
        if ($roleUuid !== null) {
            $role = Role::query()
                ->where('guard_name', 'api')
                ->where('uuid', (string) $roleUuid)
                ->firstOrFail();
            $admin->syncRoles([$role->name]);
            $newRoleName = $role->name;
        }

        $admin = $admin->fresh() ?? $admin;
        $admin->loadMissing('roles:id,name');

        $changedFields = [];
        foreach (['name', 'email', 'is_active', 'can_login'] as $field) {
            if (array_key_exists($field, $payload) && $previous[$field] !== $admin->{$field}) {
                $changedFields[] = $field;
            }
        }
        if ($roleUuid !== null && $previousRoleName !== $newRoleName) {
            $changedFields[] = 'role';
        }

        if ($changedFields !== []) {
            $metadata = [
                'admin_uuid' => $admin->uuid,
                'admin_email' => $admin->email,
                'fields' => $changedFields,
            ];
            if (in_array('role', $changedFields, true)) {
                $metadata['previous_role'] = $previousRoleName;
                $metadata['new_role'] = $newRoleName;
            }

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::ADMIN_UPDATED,
                $request,
                $actor->uuid,
                $metadata,
                'Admin user updated.',
                Admin::class,
                $admin->uuid,
                ModuleEnums::user_management,
                200,
            );
        }

        return $admin;
    }

    public function toggleActiveStatus(string $adminId, Admin $actor, Request $request): Admin
    {
        $admin = $this->resolveAdmin($adminId);
        $previousStatus = (bool) $admin->is_active;
        $isActive = ! $previousStatus;
        $admin->forceFill([
            'is_active' => $isActive,
            'can_login' => $isActive,
        ])->save();

        $admin = $admin->fresh() ?? $admin;

        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::ADMIN_STATUS_TOGGLED,
            $request,
            $actor->uuid,
            [
                'admin_uuid' => $admin->uuid,
                'admin_email' => $admin->email,
                'previous_status' => $previousStatus ? 'active' : 'inactive',
                'new_status' => $isActive ? 'active' : 'inactive',
            ],
            $isActive ? 'Admin user activated.' : 'Admin user deactivated.',
            Admin::class,
            $admin->uuid,
            ModuleEnums::user_management,
            200,
        );

        return $admin;
    }

    /**
     * @return array{audit_logs_count:int, uuid?:string, email?:string}
     */
    public function delete(string $adminId, Admin $actor, Request $request): array
    {
        $admin = $this->resolveAdmin($adminId);
        $auditLogsCount = AuditLog::query()
            ->where('user_type', UserTypeEnum::ADMIN)
            ->where('user_id', $admin->uuid)
            ->count();

        if ($auditLogsCount > 0) {
            return ['audit_logs_count' => $auditLogsCount];
        }

        $adminUuid = $admin->uuid;
        $adminEmail = $admin->email;
        $admin->tokens()->delete();
        $admin->delete();

        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::ADMIN_DELETED,
            $request,
            $actor->uuid,
            ['admin_uuid' => $adminUuid, 'admin_email' => $adminEmail],
            'Admin user deleted.',
            Admin::class,
            $adminUuid,
            ModuleEnums::user_management,
            200,
        );

        return ['audit_logs_count' => 0, 'uuid' => $adminUuid, 'email' => $adminEmail];
    }

    private function resolveAdmin(string $adminId): Admin
    {
        return Admin::query()
            ->where('uuid', $adminId)
            ->orWhere('id', is_numeric($adminId) ? (int) $adminId : -1)
            ->firstOrFail();
    }

    private function queueInviteResetLink(Admin $admin, ?string $frontendUrl = null): void
    {
        SendAdminInviteSetPasswordEmailJob::dispatch($admin->uuid, $frontendUrl);
    }
}
