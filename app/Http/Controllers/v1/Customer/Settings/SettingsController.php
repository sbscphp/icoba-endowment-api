<?php

namespace App\Http\Controllers\v1\Customer\Settings;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ChangeSettingsPasswordRequest;
use App\Http\Requests\Settings\UpdateNotificationPreferencesRequest;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Settings\AccountSettingsService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private readonly AccountSettingsService $settingsService) {}

    public function profile(Request $request)
    {
        try {
            $user = $this->requireCustomer($request);
            $profile = $this->settingsService->customerProfile($user);

            return JsonResponser::send(false, 'Profile retrieved.', $profile, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Settings\SettingsController@profile');
        }
    }

    public function changePassword(ChangeSettingsPasswordRequest $request)
    {
        try {
            $user = $this->requireCustomer($request);
            $this->settingsService->updatePassword(
                $user,
                (string) $request->input('password')
            );

            return JsonResponser::send(false, 'Password changed successfully.', null, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Settings\SettingsController@changePassword');
        }
    }

    public function updateNotificationPreferences(UpdateNotificationPreferencesRequest $request)
    {
        try {
            $user = $this->requireCustomer($request);
            $preferences = $this->settingsService->updateNotificationPreferences($user, $request->validated());

            return JsonResponser::send(false, 'Notification preferences updated.', $preferences, 200);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Settings\SettingsController@updateNotificationPreferences');
        }
    }

    private function requireCustomer(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403, 'Forbidden.');
        }

        return $user;
    }
}

