<?php

namespace App\Services\Admin\UserManagement;

use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Role;
use App\Notifications\Auth\ResetPasswordMail;
use App\Services\Auth\PasswordResetService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminUserService
{
    public function __construct(private readonly PasswordResetService $passwordResetService) {}

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

        $this->dispatchInviteResetLink($admin);

        return $admin->fresh() ?? $admin;
    }

    public function resendInviteResetLink(string $adminId): Admin
    {
        $admin = $this->resolveAdmin($adminId);

        if (! (bool) $admin->must_reset_password) {
            throw new ApiException('Reset link can only be resent for admins pending first-time password setup.', 422);
        }

        $this->dispatchInviteResetLink($admin);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{total:int,active:int,inactive:int}
     */
    public function stats(array $validated): array
    {
        $query = Admin::query();
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
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        $query = Admin::query()->with('roles:id,name');

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

        return $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);
    }

    public function findAdmin(string $adminId): Admin
    {
        return $this->resolveAdmin($adminId);
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

    private function adminSetPasswordUrl(string $resetToken): string
    {
        $base = config('app.admin_frontend_set_password_url');
        if (! is_string($base) || $base === '') {
            $frontend = rtrim((string) config('app.frontend_url'), '/');
            $base = $frontend !== '' ? $frontend.'/admin/set-password' : url('/');
        }

        $sep = str_contains($base, '?') ? '&' : '?';

        return $base.$sep.'token='.urlencode($resetToken);
    }

    private function dispatchInviteResetLink(Admin $admin): void
    {
        $resetToken = $this->passwordResetService->issueResetTokenFor($admin);
        $admin->notify(new ResetPasswordMail(
            token: $resetToken,
            resetUrl: $this->adminSetPasswordUrl($resetToken),
        ));
    }
}
