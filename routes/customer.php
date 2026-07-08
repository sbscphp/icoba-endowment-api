<?php

use App\Http\Controllers\v1\Customer\Auth\EmailVerificationController;
use App\Http\Controllers\v1\Customer\Auth\LoginController;
use App\Http\Controllers\v1\Customer\Auth\PasswordController;
use App\Http\Controllers\v1\Customer\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
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
        Route::post('refresh', [LoginController::class, 'refresh'])->middleware('throttle:customer-token-refresh');

        Route::middleware('auth:sanctum')->post('logout', [LoginController::class, 'logout']);
    });
});
