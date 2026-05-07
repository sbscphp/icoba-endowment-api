<?php

namespace App\Http\Controllers\v1\Admin\Settings;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ChangeSettingsPasswordRequest;
use App\Http\Requests\Settings\UpdateNotificationPreferencesRequest;
use App\Models\Admin;
use App\Responser\JsonResponser;
use App\Services\Settings\AccountSettingsService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private readonly AccountSettingsService $settingsService) {}

    public function profile(Request $request)
    {
        try {
            $admin = $this->requireAdmin($request);
            $profile = $this->settingsService->adminProfile($admin);

            return JsonResponser::send(false, 'Profile retrieved.', $profile, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Settings\SettingsController@profile');
        }
    }

    public function changePassword(ChangeSettingsPasswordRequest $request)
    {
        try {
            $admin = $this->requireAdmin($request);
            $this->settingsService->updatePassword(
                $admin,
                (string) $request->input('password')
            );

            return JsonResponser::send(false, 'Password changed successfully.', null, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Settings\SettingsController@changePassword');
        }
    }

    public function updateNotificationPreferences(UpdateNotificationPreferencesRequest $request)
    {
        try {
            $admin = $this->requireAdmin($request);
            $preferences = $this->settingsService->updateNotificationPreferences($admin, $request->validated());

            return JsonResponser::send(false, 'Notification preferences updated.', $preferences, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Settings\SettingsController@updateNotificationPreferences');
        }
    }

    private function requireAdmin(Request $request): Admin
    {
        $admin = $request->user();
        if (! $admin instanceof Admin) {
            abort(403, 'Forbidden.');
        }

        return $admin;
    }
}

