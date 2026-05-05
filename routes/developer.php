<?php

use App\Http\Controllers\Developer\ApiCryptoHelperController;
use App\Http\Controllers\Developer\ApiUserRegistrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('dev/api-users', [ApiUserRegistrationController::class, 'store'])
        ->middleware('throttle:api-user-dev-registration');

    Route::post('dev/crypto/encrypt', [ApiCryptoHelperController::class, 'encrypt'])
        ->middleware('throttle:api-user-dev-registration');

    Route::post('dev/crypto/decrypt', [ApiCryptoHelperController::class, 'decrypt'])
        ->middleware('throttle:api-user-dev-registration');
});
