<?php

use App\Http\Controllers\v1\Customer\Auth\EmailVerificationController;
use App\Http\Controllers\v1\Customer\Auth\LoginController;
use App\Http\Controllers\v1\Customer\Auth\PasswordController;
use App\Http\Controllers\v1\Customer\Auth\RegisterController;
use App\Http\Controllers\v1\Customer\Notification\NotificationController;
use App\Http\Controllers\v1\Customer\Settings\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('register/options', [RegisterController::class, 'metadata'])->middleware('throttle:60,1');
        Route::post('signup', [RegisterController::class, 'store'])->middleware('throttle:customer-register');
        Route::post('email/verify-otp', [EmailVerificationController::class, 'verify'])->middleware('throttle:customer-otp-verify');
        Route::post('email/resend-otp', [EmailVerificationController::class, 'resend'])->middleware('throttle:customer-otp-send');

        Route::post('login', [LoginController::class, 'login'])->middleware('throttle:customer-login');
        Route::post('login/verify-otp', [LoginController::class, 'verifyOtp'])->middleware('throttle:customer-otp-verify');
        Route::post('login/resend-otp', [LoginController::class, 'resendOtp'])->middleware('throttle:customer-otp-send');

        Route::post('forgot-password', [PasswordController::class, 'forgotPassword'])->middleware('throttle:customer-otp-send');
        Route::post('forgot-password/resend', [PasswordController::class, 'forgotPasswordResend'])->middleware('throttle:customer-otp-send');
        Route::post('forgot-password/verify', [PasswordController::class, 'forgotPasswordVerify'])->middleware('throttle:customer-otp-verify');
        Route::post('reset-password', [PasswordController::class, 'resetPassword'])->middleware('throttle:customer-otp-verify');

        Route::middleware('auth:sanctum')->post('logout', [LoginController::class, 'logout']);
    });

    Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('/{id}', [NotificationController::class, 'show']);
        Route::patch('/{id}/read', [NotificationController::class, 'markRead']);
        Route::patch('/{id}/unread', [NotificationController::class, 'markUnread']);
    });

    Route::middleware('auth:sanctum')->prefix('settings')->group(function () {
        Route::get('/profile', [SettingsController::class, 'profile']);
        Route::patch('/password', [SettingsController::class, 'changePassword']);
        Route::patch('/notifications', [SettingsController::class, 'updateNotificationPreferences']);
    });
});
