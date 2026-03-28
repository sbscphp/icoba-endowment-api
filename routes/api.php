<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\v1\Auth\LoginController;
use App\Http\Controllers\v1\Auth\RegisterController;
use App\Http\Controllers\v1\Auth\PasswordController;


Route::prefix('v1')->group(function () {

    //AUTH ROUTES
    Route::prefix('auth')->group(function () {

        Route::post('register', [RegisterController::class, 'store']);
        Route::post('login', [LoginController::class, 'store']);

        Route::post('login/verify-otp', [LoginController::class, 'verifyOtp']);
        Route::post('login/resend-otp', [LoginController::class, 'resendOtp']);

        Route::post('forgot-password', [PasswordController::class, 'forgotPassword']);
        Route::post('reset-password', [PasswordController::class, 'resetPassword']);

        Route::middleware('auth:sanctum')->post('logout', [LoginController::class, 'logout']);
    });


    //ADMIN ROUTES
    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
        Route::get('/users', fn () => 'admin only');
    });
        
    // Route::middleware(['auth:sanctum', 'abilities:admin'])->group(function () {
    //     // admin endpoints
    // });
});
