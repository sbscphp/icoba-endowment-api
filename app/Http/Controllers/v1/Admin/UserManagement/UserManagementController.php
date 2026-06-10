<?php

namespace App\Http\Controllers\v1\Admin\UserManagement;

use App\Enums\eRole;
use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DateRangeStatsRequest;
use App\Http\Requests\Admin\UserManagement\AdminListRequest;
use App\Http\Requests\Admin\UserManagement\CreateAdminRequest;
use App\Http\Requests\Admin\UserManagement\CreateRoleRequest;
use App\Http\Requests\Admin\UserManagement\RoleListRequest;
use App\Http\Requests\Admin\UserManagement\UpdateAdminRequest;
use App\Http\Requests\Admin\UserManagement\UpdateRoleRequest;
use App\Http\Resources\AdminFullResource;
use App\Http\Resources\AdminListResource;
use App\Http\Resources\RoleListResource;
use App\Http\Resources\RoleResource;
use App\Models\Admin;
use App\Models\Role;
use App\Responser\JsonResponser;
use App\Services\Admin\UserManagement\AdminUserService;
use App\Services\Admin\UserManagement\RoleService;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserManagementController extends Controller
{
    public function __construct(
        private readonly AdminUserService $adminUserService,
        private readonly RoleService $roleService,
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    public function adminStats(DateRangeStatsRequest $request)
    {
        try {
            return JsonResponser::send(false, 'Admin stats retrieved.', $this->adminUserService->stats($request->validated()));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@adminStats');
        }
    }

    public function roleStats(DateRangeStatsRequest $request)
    {
        try {
            return JsonResponser::send(false, 'Role stats retrieved.', $this->roleService->stats($request->validated()));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@roleStats');
        }
    }

    public function createRole(CreateRoleRequest $request)
    {
        try {
            $role = $this->roleService->create($request->validated());

            return JsonResponser::send(false, 'Role created successfully.', RoleResource::make($role)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@createRole');
        }
    }

    public function roleList(RoleListRequest $request)
    {
        try {
            $listing = $request->validated();
            $export = $listing['export'] ?? null;

            return match ($export) {
                'csv' => $this->respondRoleCsv($listing),
                'pdf' => $this->respondRolePdf($listing),
                default => JsonResponser::send(false, 'Roles retrieved.', $this->paginatedPayload($this->roleService->list($listing), RoleListResource::class)),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@roleList');
        }
    }

    public function adminList(AdminListRequest $request)
    {
        try {
            $listing = $request->validated();
            $export = $listing['export'] ?? null;

            return match ($export) {
                'csv' => $this->respondAdminCsv($listing),
                'pdf' => $this->respondAdminPdf($listing),
                default => JsonResponser::send(false, 'Admin users retrieved.', $this->paginatedPayload($this->adminUserService->list($listing), AdminListResource::class)),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@adminList');
        }
    }

    public function rolesWithPermissions()
    {
        try {
            $roles = $this->roleService->listWithPermissions();

            return JsonResponser::send(
                false,
                'Roles with permissions retrieved.',
                RoleResource::collection($roles)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@rolesWithPermissions');
        }
    }

    public function permissionList()
    {
        try {
            return JsonResponser::send(false, 'Permissions retrieved.', $this->roleService->listAllPermissions());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@permissionList');
        }
    }

    public function roleDropdown(?string $status = null)
    {
        try {
            $roles = $this->roleService->dropdown($status ?? 'active');
            $payload = $roles->map(fn ($role) => [
                'role_id' => $role->uuid,
                'name' => $role->name,
                'is_active' => (bool) $role->is_active,
            ])->values()->all();

            return JsonResponser::send(false, 'Role dropdown retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@roleDropdown');
        }
    }

    public function adminDropdown(?string $status = null)
    {
        try {
            $admins = $this->adminUserService->dropdown($status ?? 'active');
            $payload = $admins->map(fn ($admin) => [
                'admin_id' => $admin->uuid,
                'name' => $admin->name,
                'email' => $admin->email,
                'is_active' => (bool) $admin->is_active,
            ])->values()->all();

            return JsonResponser::send(false, 'Admin dropdown retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@adminDropdown');
        }
    }

    public function createAdmin(CreateAdminRequest $request)
    {
        try {
            $this->assertCanAssignSuperAdminRole($request->validated('role_id'));
            $admin = $this->adminUserService->create($request->validated());

            return JsonResponser::send(false, 'Admin user created successfully.', AdminFullResource::make($admin)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@createAdmin');
        }
    }

    public function viewAdmin(string $adminId)
    {
        try {
            $admin = $this->adminUserService->findAdmin($adminId);

            return JsonResponser::send(false, 'Admin user retrieved.', AdminFullResource::make($admin)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@viewAdmin');
        }
    }

    public function updateAdmin(UpdateAdminRequest $request, string $adminId)
    {
        try {
            $roleUuid = $request->validated('role_id');
            if (is_string($roleUuid) && $roleUuid !== '') {
                $this->assertCanAssignSuperAdminRole($roleUuid);
            }
            $admin = $this->adminUserService->update($adminId, $request->validated());

            return JsonResponser::send(false, 'Admin user updated.', AdminFullResource::make($admin)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@updateAdmin');
        }
    }

    public function setAdminActiveStatus(string $adminId)
    {
        try {
            $authAdmin = request()->user();
            if ($authAdmin instanceof Admin) {
                $targetAdmin = $this->adminUserService->findAdmin($adminId);
                if ((string) $authAdmin->id === (string) $targetAdmin->id) {
                    return JsonResponser::send(true, 'You cannot toggle your own status as an admin.', null, 422);
                }
            }

            $admin = $this->adminUserService->toggleActiveStatus($adminId);
            $message = (bool) $admin->is_active ? 'Admin user activated.' : 'Admin user deactivated.';

            return JsonResponser::send(false, $message, AdminFullResource::make($admin)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@setAdminActiveStatus');
        }
    }

    public function resendAdminInviteLink(string $adminId)
    {
        try {
            $admin = $this->adminUserService->resendInviteResetLink($adminId);

            return JsonResponser::send(false, 'Password setup link resent successfully.', AdminFullResource::make($admin)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@resendAdminInviteLink');
        }
    }

    public function deleteAdmin(string $adminId)
    {
        try {
            $result = $this->adminUserService->delete($adminId);
            $auditLogsCount = $result['audit_logs_count'];
            if ($auditLogsCount > 0) {
                return JsonResponser::send(true, 'Admin cannot be deleted because of audit logs tied to them.', [
                    'audit_logs_count' => $auditLogsCount,
                ], 422);
            }

            return JsonResponser::send(false, 'Admin user deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@deleteAdmin');
        }
    }

    public function viewRole(string $roleId)
    {
        try {
            $role = $this->roleService->findRole($roleId);

            return JsonResponser::send(false, 'Role retrieved.', RoleResource::make($role)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@viewRole');
        }
    }

    public function updateRole(UpdateRoleRequest $request, string $roleId)
    {
        try {
            $role = $this->roleService->update($roleId, $request->validated());

            return JsonResponser::send(false, 'Role updated.', RoleResource::make($role)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@updateRole');
        }
    }

    public function setRoleActiveStatus(string $roleId)
    {
        try {
            $role = $this->roleService->toggleActiveStatus($roleId);
            $message = (bool) $role->is_active ? 'Role activated.' : 'Role deactivated.';

            return JsonResponser::send(false, $message, RoleResource::make($role)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@setRoleActiveStatus');
        }
    }

    public function deleteRole(string $roleId)
    {
        try {
            $result = $this->roleService->delete($roleId);
            $adminUsersCount = $result['admin_users_count'];
            if ($adminUsersCount > 0) {
                return JsonResponser::send(true, 'Role cannot be deleted because it is assigned to one or more admin users.', [
                    'admin_users_count' => $adminUsersCount,
                ], 422);
            }

            return JsonResponser::send(false, 'Role deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@deleteRole');
        }
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondRoleCsv(array $listing): StreamedResponse
    {
        [$collection, $truncated] = $this->roleService->exportCollection($listing);
        $filename = 'roles-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($collection): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Role ID',
                'Name',
                'Number of users',
                'Status',
                'Last updated',
            ]);

            foreach ($collection as $role) {
                /** @var Role $role */
                fputcsv($out, [
                    $role->uuid,
                    $role->name,
                    (int) ($role->users_count ?? 0),
                    $role->is_active ? 'active' : 'inactive',
                    $role->updated_at?->toIso8601String() ?? '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Truncated' => $truncated ? '1' : '0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondRolePdf(array $listing)
    {
        [$collection, $truncated] = $this->roleService->exportCollection($listing);
        $filename = 'roles-'.now()->format('Y-m-d-His').'.pdf';
        $periodStart = ! empty($listing['start_date']) ? (string) $listing['start_date'] : 'All dates';
        $periodEnd = ! empty($listing['end_date']) ? (string) $listing['end_date'] : 'All dates';

        $headings = [
            'Role ID',
            'Name',
            'Number of users',
            'Status',
            'Last updated',
        ];

        $rows = $collection->map(fn (Role $role): array => [
            $role->uuid,
            $role->name,
            (string) (int) ($role->users_count ?? 0),
            $role->is_active ? 'active' : 'inactive',
            $role->updated_at?->format('Y-m-d H:i') ?? '',
        ]);

        return $this->pdfReportHelper->download(
            rows: $rows,
            headings: $headings,
            title: 'Roles',
            filename: $filename,
            orientation: 'landscape',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            generatedAt: now((string) config('app.timezone')),
            truncated: $truncated,
            includedRows: $rows->count(),
        );
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondAdminCsv(array $listing): StreamedResponse
    {
        [$collection, $truncated] = $this->adminUserService->exportCollection($listing);
        $filename = 'admin-users-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($collection): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Admin ID',
                'Name',
                'Role',
                'Email',
                'Last active',
                'Status',
            ]);

            foreach ($collection as $admin) {
                /** @var Admin $admin */
                fputcsv($out, [
                    $admin->uuid,
                    $admin->name,
                    $admin->roles->first()?->name ?? '',
                    $admin->email,
                    $admin->last_active_at?->toIso8601String() ?? '',
                    $admin->is_active ? 'active' : 'inactive',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Truncated' => $truncated ? '1' : '0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondAdminPdf(array $listing)
    {
        [$collection, $truncated] = $this->adminUserService->exportCollection($listing);
        $filename = 'admin-users-'.now()->format('Y-m-d-His').'.pdf';
        $periodStart = ! empty($listing['start_date']) ? (string) $listing['start_date'] : 'All dates';
        $periodEnd = ! empty($listing['end_date']) ? (string) $listing['end_date'] : 'All dates';

        $headings = [
            'Admin ID',
            'Name',
            'Role',
            'Email',
            'Last active',
            'Status',
        ];

        $rows = $collection->map(fn (Admin $admin): array => [
            $admin->uuid,
            $admin->name,
            $admin->roles->first()?->name ?? '',
            $admin->email,
            $admin->last_active_at?->format('Y-m-d H:i') ?? '',
            $admin->is_active ? 'active' : 'inactive',
        ]);

        return $this->pdfReportHelper->download(
            rows: $rows,
            headings: $headings,
            title: 'Admin users',
            filename: $filename,
            orientation: 'landscape',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            generatedAt: now((string) config('app.timezone')),
            truncated: $truncated,
            includedRows: $rows->count(),
        );
    }

    /**
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     * @return array<string, mixed>
     */
    private function paginatedPayload(LengthAwarePaginator $paginator, string $resourceClass): array
    {
        $payload = $paginator->toArray();
        /** @var \Illuminate\Http\Resources\Json\AnonymousResourceCollection $resource */
        $resource = $resourceClass::collection($paginator);
        $payload['data'] = $resource->resolve();

        return $payload;
    }

    private function assertCanAssignSuperAdminRole(string $roleUuid): void
    {
        $role = Role::query()
            ->where('guard_name', 'api')
            ->where('uuid', $roleUuid)
            ->first();

        if ($role === null || $role->name !== eRole::SUPER_ADMIN->value) {
            return;
        }

        $authAdmin = request()->user();
        if (! ($authAdmin instanceof Admin) || ! $authAdmin->hasRole(eRole::SUPER_ADMIN->value)) {
            abort(403, 'Only a Super Admin can assign the Super Admin role.');
        }
    }
}
