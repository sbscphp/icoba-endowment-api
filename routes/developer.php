<?php

use App\Http\Controllers\Developer\ApiCryptoHelperController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('dev/crypto/encrypt', [ApiCryptoHelperController::class, 'encrypt'])
        ->middleware('throttle:api-user-dev-registration');

    Route::post('dev/crypto/encrypt-json', [ApiCryptoHelperController::class, 'encryptJson'])
        ->middleware('throttle:api-user-dev-registration');

    Route::post('dev/crypto/encrypt-form', [ApiCryptoHelperController::class, 'encryptForm'])
        ->middleware('throttle:api-user-dev-registration');

    Route::post('dev/crypto/decrypt', [ApiCryptoHelperController::class, 'decrypt'])
        ->middleware('throttle:api-user-dev-registration');
});
