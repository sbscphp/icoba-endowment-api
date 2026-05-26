<?php

use App\Http\Controllers\v1\Customer\Recognition\CustomerRecognitionController;
use App\Http\Controllers\v1\Customer\Auth\EmailVerificationController;
use App\Http\Controllers\v1\Customer\Auth\LoginController;
use App\Http\Controllers\v1\Customer\Auth\PasswordController;
use App\Http\Controllers\v1\Customer\Auth\RegisterController;
use App\Http\Controllers\v1\Customer\CustomerDashboardController;
use App\Http\Controllers\v1\Customer\Donation\BankTransferController;
use App\Http\Controllers\v1\Customer\Donation\CustomerReceiptController;
use App\Http\Controllers\v1\Customer\Donation\DonationIntentController;
use App\Http\Controllers\v1\Customer\Donation\DonationCheckoutController;
use App\Http\Controllers\v1\Customer\Notification\NotificationController;
use App\Http\Controllers\v1\Customer\Pledge\CustomerPledgeController;
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
        Route::delete('/{id}/dismiss', [NotificationController::class, 'dismiss']);
    });

    Route::middleware('auth:sanctum')->prefix('settings')->group(function () {
        Route::get('/profile', [SettingsController::class, 'profile']);
        Route::patch('/profile', [SettingsController::class, 'updateProfile']);
        Route::post('/profile', [SettingsController::class, 'updateProfile']);
        Route::patch('/2fa', [SettingsController::class, 'toggleTwoFactor']);
        Route::post('/2fa', [SettingsController::class, 'toggleTwoFactor']);
        Route::patch('/password', [SettingsController::class, 'changePassword']);
        Route::post('/password', [SettingsController::class, 'changePassword']);
        Route::patch('/notifications', [SettingsController::class, 'updateNotificationPreferences']);
        Route::post('/notifications', [SettingsController::class, 'updateNotificationPreferences']);
    });

    Route::middleware('auth:sanctum')->prefix('me')->group(function () {
        Route::get('/dashboard/summary', [CustomerDashboardController::class, 'summary']);
        Route::get('/transactions', [CustomerDashboardController::class, 'transactionHistory']);
        Route::get('/transactions/{transactionUuid}/receipt', [CustomerReceiptController::class, 'download']);
        Route::get('/transactions/{transactionUuid}/tax-receipt', [CustomerReceiptController::class, 'downloadTax']);

        Route::get('/pledges/stats', [CustomerPledgeController::class, 'stats']);
        Route::get('/pledges/overdue', [CustomerPledgeController::class, 'overdue']);
        Route::get('/pledges', [CustomerPledgeController::class, 'index']);
        Route::get('/pledges/{pledgeUuid}', [CustomerPledgeController::class, 'show'])
            ->whereUuid('pledgeUuid');
        Route::patch('/pledges/{pledgeUuid}/pause', [CustomerPledgeController::class, 'updatePause'])
            ->whereUuid('pledgeUuid');
        Route::post('/pledges/{pledgeUuid}/pause', [CustomerPledgeController::class, 'updatePause'])
            ->whereUuid('pledgeUuid');
        // Route::patch('/pledges/{pledgeUuid}/payment-preference', [CustomerPledgeController::class, 'updateSchedule'])
        //     ->whereUuid('pledgeUuid');
        // Route::post('/pledges/{pledgeUuid}/payment-preference', [CustomerPledgeController::class, 'updateSchedule'])
        //     ->whereUuid('pledgeUuid');
        Route::get('/recognitions', [CustomerRecognitionController::class, 'index']);
        Route::get('/recognitions/{recognitionUuid}/download', [CustomerRecognitionController::class, 'download'])
            ->whereUuid('recognitionUuid');
        Route::post('/donations/intent', [DonationIntentController::class, 'store'])
            ->middleware(['throttle:60,1']);
    });

    // Route::post('donations/intent', [DonationIntentController::class, 'store'])
    //     ->middleware(['throttle:60,1']);

    Route::post('donations/checkout', [DonationCheckoutController::class, 'store'])
        ->middleware(['throttle:60,1']);

    Route::post('donations/checkout/verify', [DonationCheckoutController::class, 'verify'])
        ->middleware(['throttle:60,1']);

    Route::post('donations/bank-transfer/intent', [BankTransferController::class, 'intent'])
        ->middleware(['throttle:60,1']);

    Route::post('donations/bank-transfer/{transactionUuid}/confirm-payment', [BankTransferController::class, 'confirmPayment'])
        ->whereUuid('transactionUuid')
        ->middleware(['throttle:60,1']);

    Route::post('pledges', [CustomerPledgeController::class, 'store'])
        ->middleware(['throttle:60,1']);
});
