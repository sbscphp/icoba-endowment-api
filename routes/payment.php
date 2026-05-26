<?php

use App\Http\Controllers\v1\Payment\PaystackWebhookController;
use App\Http\Controllers\v1\Payment\StripeWebhookController;
use App\Http\Controllers\v1\Webhook\FcmbWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/payment')->group(function (): void {
    Route::post('stripe/webhook', [StripeWebhookController::class, 'handle'])
        ->middleware(['throttle:120,1']);

    Route::post('paystack/webhook', [PaystackWebhookController::class, 'handle'])
        ->middleware(['throttle:120,1']);
});

Route::prefix('v1/webhooks')->group(function (): void {
    Route::post('fcmb/transactions', [FcmbWebhookController::class, 'handle'])
        ->middleware(['throttle:60,1']);
});
