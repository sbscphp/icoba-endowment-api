<?php

namespace App\Services\Admin\UserManagement;

use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Role;
use App\Jobs\SendAdminInviteSetPasswordEmailJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdminUserService
{
    private const MAX_EXPORT_ROWS = 5000;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): Admin
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

        return $admin->fresh() ?? $admin;
    }

    public function resendInviteResetLink(string $adminId): Admin
    {
        $admin = $this->resolveAdmin($adminId);

        if (! (bool) $admin->must_reset_password) {
            throw new ApiException('Reset link can only be resent for admins pending first-time password setup.', 422);
        }

        $this->queueInviteResetLink($admin);

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
    public function update(string $adminId, array $payload): Admin
    {
        $admin = $this->resolveAdmin($adminId);
        $roleUuid = $payload['role_id'] ?? null;
        unset($payload['role_id']);

        if ($payload !== []) {
            $admin->fill($payload)->save();
        }

        if ($roleUuid !== null) {
            $role = Role::query()
                ->where('guard_name', 'api')
                ->where('uuid', (string) $roleUuid)
                ->firstOrFail();
            $admin->syncRoles([$role->name]);
        }

        return $admin->fresh() ?? $admin;
    }

    public function toggleActiveStatus(string $adminId): Admin
    {
        $admin = $this->resolveAdmin($adminId);
        $isActive = ! ((bool) $admin->is_active);
        $admin->forceFill([
            'is_active' => $isActive,
            'can_login' => $isActive,
        ])->save();

        return $admin->fresh() ?? $admin;
    }

    /**
     * @return array{audit_logs_count:int}
     */
    public function delete(string $adminId): array
    {
        $admin = $this->resolveAdmin($adminId);
        $auditLogsCount = AuditLog::query()
            ->where('user_type', UserTypeEnum::ADMIN)
            ->where('user_id', $admin->uuid)
            ->count();

        if ($auditLogsCount > 0) {
            return ['audit_logs_count' => $auditLogsCount];
        }

        $admin->tokens()->delete();
        $admin->delete();

        return ['audit_logs_count' => 0];
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
