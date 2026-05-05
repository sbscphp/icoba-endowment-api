<?php

use App\Http\Controllers\v1\Admin\Auth\AdminLoginController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AdminLoginController::class, 'login'])->middleware('throttle:admin-login');
        Route::post('login/verify-otp', [AdminLoginController::class, 'verifyOtp'])->middleware('throttle:admin-otp-verify');
        Route::post('login/resend-otp', [AdminLoginController::class, 'resendOtp'])->middleware('throttle:admin-otp-send');
        Route::middleware('auth:sanctum')->post('logout', [AdminLoginController::class, 'logout']);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::get('/users', fn () => 'admin only');
    });
});
