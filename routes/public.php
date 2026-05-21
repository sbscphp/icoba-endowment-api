<?php

use App\Http\Controllers\v1\Public\LeaderboardController;
use App\Http\Controllers\v1\Public\PublicCampaignController;
use App\Http\Controllers\v1\Public\ReceiptDownloadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('public')->middleware(['throttle:public-leaderboard'])->group(function () {
        Route::get('leaderboard', [LeaderboardController::class, 'leaderboard']);
        Route::get('leaderboard/sets', [LeaderboardController::class, 'sets']);
        Route::get('leaderboard/top-sets', [LeaderboardController::class, 'topSets']);
        Route::get('leaderboard/recent-donations', [LeaderboardController::class, 'recentDonations']);

        Route::get('campaigns', [PublicCampaignController::class, 'index']);
        Route::get('campaigns/dropdown', [PublicCampaignController::class, 'dropdown']);
        Route::get('campaigns/{campaignUuid}/fund-progress', [LeaderboardController::class, 'fundProgress']);
    });

    Route::get('receipts/{receiptNumber}/download', [ReceiptDownloadController::class, 'guestPdf'])
        ->middleware(['throttle:public-receipt']);
    Route::get('receipts/{receiptNumber}/tax/download', [ReceiptDownloadController::class, 'guestTaxPdf'])
        ->middleware(['throttle:public-receipt']);
});
