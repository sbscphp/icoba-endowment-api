<?php

use App\Http\Controllers\v1\Public\ContactSubmissionController;
use App\Http\Controllers\v1\Public\LeaderboardController;
use App\Http\Controllers\v1\Public\PublicCampaignController;
use App\Http\Controllers\v1\Public\PublicCampaignUpdateReportController;
use App\Http\Controllers\v1\Public\PublicBankAccountController;
use App\Http\Controllers\v1\Public\PublicTierController;
use App\Http\Controllers\v1\Public\ReceiptDownloadController;
use App\Http\Controllers\v1\Public\RecognitionDownloadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('public')->middleware(['throttle:public-leaderboard'])->group(function () {
        Route::get('leaderboard', [LeaderboardController::class, 'leaderboard']);
        Route::get('leaderboard/sets', [LeaderboardController::class, 'sets']);
        Route::get('leaderboard/top-sets', [LeaderboardController::class, 'topSets']);
        Route::get('leaderboard/recent-donations', [LeaderboardController::class, 'recentDonations']);

        Route::get('campaigns', [PublicCampaignController::class, 'index']);
        Route::get('campaigns/dropdown', [PublicCampaignController::class, 'dropdown']);

        Route::get('tiers', [PublicTierController::class, 'index']);
        Route::get('bank-accounts', [PublicBankAccountController::class, 'index']);
        Route::get('campaigns/fund-progress', [LeaderboardController::class, 'fundProgressList']);
        Route::get('campaigns/{campaignUuid}/fund-progress', [LeaderboardController::class, 'fundProgress']);
        Route::get('campaigns/{campaignUuid}', [PublicCampaignController::class, 'show'])
            ->whereUuid('campaignUuid');

        Route::get('receipts/{receiptNumber}/download', [ReceiptDownloadController::class, 'guestPdf'])
            ->middleware(['throttle:public-receipt']);
        Route::get('receipts/{receiptNumber}/tax/download', [ReceiptDownloadController::class, 'guestTaxPdf'])
            ->middleware(['throttle:public-receipt']);
        Route::get('recognitions/{recognitionNumber}/download', [RecognitionDownloadController::class, 'download'])
            ->middleware(['throttle:public-receipt']);

        Route::post('contact', [ContactSubmissionController::class, 'store'])
            ->middleware(['throttle:60,1']);

        Route::prefix('blog/report')->group(function () {
            Route::get('/', [PublicCampaignUpdateReportController::class, 'index']);
            Route::get('/{reportId}/download', [PublicCampaignUpdateReportController::class, 'download'])
                ->middleware(['throttle:public-receipt']);
            Route::get('/{reportId}', [PublicCampaignUpdateReportController::class, 'show']);
        });
    });

    // Route::get('receipts/{receiptNumber}/download', [ReceiptDownloadController::class, 'guestPdf'])
    //     ->middleware(['throttle:public-receipt']);
    // Route::get('receipts/{receiptNumber}/tax/download', [ReceiptDownloadController::class, 'guestTaxPdf'])
    //     ->middleware(['throttle:public-receipt']);
});
