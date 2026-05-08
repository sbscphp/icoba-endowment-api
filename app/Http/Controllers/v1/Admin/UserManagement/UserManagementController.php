<?php

namespace App\Http\Controllers\v1\Admin\UserManagement;

use App\Enums\eRole;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\CreateAdminRequest;
use App\Http\Requests\Admin\CreateRoleRequest;
use App\Http\Requests\Admin\DateRangeStatsRequest;
use App\Http\Requests\Admin\RoleListRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
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

class UserManagementController extends Controller
{
    public function __construct(
        private readonly AdminUserService $adminUserService,
        private readonly RoleService $roleService,
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
            $paginator = $this->roleService->list($request->validated());

            return JsonResponser::send(false, 'Roles retrieved.', $this->paginatedPayload($paginator, RoleListResource::class));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@roleList');
        }
    }

    public function adminList(AdminListRequest $request)
    {
        try {
            $paginator = $this->adminUserService->list($request->validated());

            return JsonResponser::send(false, 'Admin users retrieved.', $this->paginatedPayload($paginator, AdminListResource::class));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\UserManagement\UserManagementController@adminList');
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
