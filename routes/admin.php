<?php

use App\Http\Controllers\v1\Admin\AuditTrail\AuditTrailController;
use App\Http\Controllers\v1\Admin\Auth\AdminLoginController;
use App\Http\Controllers\v1\Admin\Notification\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AdminLoginController::class, 'login'])->middleware('throttle:admin-login');
        Route::post('login/verify-otp', [AdminLoginController::class, 'verifyOtp'])->middleware('throttle:admin-otp-verify');
        Route::post('login/resend-otp', [AdminLoginController::class, 'resendOtp'])->middleware('throttle:admin-otp-send');
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
});
