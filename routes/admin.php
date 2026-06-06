<?php

use App\Http\Controllers\v1\Admin\AuditTrail\AuditTrailController;
use App\Http\Controllers\v1\Admin\Auth\AdminLoginController;
use App\Http\Controllers\v1\Admin\Auth\PasswordController as AdminPasswordController;
use App\Http\Controllers\v1\Admin\Notification\NotificationController;
use App\Http\Controllers\v1\Admin\Settings\SettingsController;
use App\Http\Controllers\v1\Admin\UserManagement\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AdminLoginController::class, 'login'])->middleware('throttle:admin-login');
        Route::post('login/verify-otp', [AdminLoginController::class, 'verifyOtp'])->middleware('throttle:admin-otp-verify');
        Route::post('login/resend-otp', [AdminLoginController::class, 'resendOtp'])->middleware('throttle:admin-otp-send');
        Route::post('forgot-password', [AdminPasswordController::class, 'forgotPassword'])->middleware('throttle:admin-otp-send');
        Route::post('forgot-password/resend', [AdminPasswordController::class, 'forgotPasswordResend'])->middleware('throttle:admin-otp-send');
        Route::post('forgot-password/verify', [AdminPasswordController::class, 'forgotPasswordVerify'])->middleware('throttle:admin-otp-verify');
        Route::post('reset-password', [AdminPasswordController::class, 'resetPassword'])->middleware('throttle:admin-otp-verify');
        Route::middleware('auth:sanctum')->post('logout', [AdminLoginController::class, 'logout']);
    });

    Route::get('audit-trails', [AuditTrailController::class, 'index'])
        ->middleware(['auth:sanctum', 'permission:audit_trail.read']);

    Route::prefix('notifications')->middleware(['auth:sanctum'])->group(function () {
        Route::middleware(['permission:notifications.read'])->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/{id}', [NotificationController::class, 'show']);
        });

        Route::middleware(['permission:notifications.update'])->group(function () {
            Route::post('/read-all', [NotificationController::class, 'markAllRead']);
            Route::patch('/{id}/read', [NotificationController::class, 'markRead']);
            Route::patch('/{id}/unread', [NotificationController::class, 'markUnread']);
        });
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::get('/users', fn () => 'admin only');
    });

    Route::prefix('settings')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/profile', [SettingsController::class, 'profile']);
        Route::patch('/profile', [SettingsController::class, 'updateProfile']);
        Route::patch('/password', [SettingsController::class, 'changePassword']);
        Route::post('/password', [SettingsController::class, 'changePassword']);
        Route::patch('/notifications', [SettingsController::class, 'updateNotificationPreferences']);
        Route::post('/notifications', [SettingsController::class, 'updateNotificationPreferences']);
    });

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::prefix('roles')->group(function () {
            Route::get('/stats', [UserManagementController::class, 'roleStats'])
                ->middleware(['permission:roles.read']);
            Route::post('/', [UserManagementController::class, 'createRole'])
                ->middleware(['permission:roles.create']);
            Route::get('/', [UserManagementController::class, 'roleList'])
                ->middleware(['permission:roles.read']);
            Route::get('/{roleId}', [UserManagementController::class, 'viewRole'])
                ->middleware(['permission:roles.read']);
            Route::patch('/{roleId}', [UserManagementController::class, 'updateRole'])
                ->middleware(['permission:roles.update']);
            Route::patch('/{roleId}/toggle-status', [UserManagementController::class, 'setRoleActiveStatus'])
                ->middleware(['permission:roles.update']);
            Route::delete('/{roleId}', [UserManagementController::class, 'deleteRole'])
                ->middleware(['permission:roles.delete']);
        });

        Route::prefix('admin-users')->group(function () {
            Route::get('/stats', [UserManagementController::class, 'adminStats'])
            ->middleware(['permission:admins.read']);
            Route::post('/', [UserManagementController::class, 'createAdmin'])
                ->middleware(['permission:admins.create']);
            Route::get('/', [UserManagementController::class, 'adminList'])
                ->middleware(['permission:admins.read']);
            Route::get('/{adminId}', [UserManagementController::class, 'viewAdmin'])
                ->middleware(['permission:admins.read']);
            Route::patch('/{adminId}', [UserManagementController::class, 'updateAdmin'])
                ->middleware(['permission:admins.update']);
            Route::patch('/{adminId}/toggle-status', [UserManagementController::class, 'setAdminActiveStatus'])
                ->middleware(['permission:admins.update']);
            Route::post('/{adminId}/resend-invite-link', [UserManagementController::class, 'resendAdminInviteLink'])
                ->middleware(['permission:admins.update']);
            Route::delete('/{adminId}', [UserManagementController::class, 'deleteAdmin'])
                ->middleware(['permission:admins.delete']);
        });
    });
});
