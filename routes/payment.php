<?php

use App\Http\Controllers\v1\Payment\PaystackWebhookController;
use App\Http\Controllers\v1\Payment\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/payment')->group(function (): void {
    Route::post('stripe/webhook', [StripeWebhookController::class, 'handle'])
        ->middleware(['throttle:120,1']);

    Route::post('paystack/webhook', [PaystackWebhookController::class, 'handle'])
        ->middleware(['throttle:120,1']);
});
