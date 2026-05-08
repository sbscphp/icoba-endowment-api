<?php

namespace App\Http\Controllers\v1\Admin\Dashboard;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Dashboard\DashboardFilterRequest;
use App\Http\Requests\Admin\Dashboard\DashboardTrendRequest;
use App\Responser\JsonResponser;
use App\Services\Admin\Dashboard\DashboardService;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function overview(DashboardFilterRequest $request)
    {
        try {
            return JsonResponser::send(
                false,
                'Dashboard overview retrieved.',
                $this->dashboardService->overview($request->validated())
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Dashboard\DashboardController@overview');
        }
    }

    public function campaignContributionTrend(DashboardTrendRequest $request)
    {
        try {
            return JsonResponser::send(
                false,
                'Campaign contribution trend retrieved.',
                $this->dashboardService->campaignContributionTrend($request->validated())
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Dashboard\DashboardController@campaignContributionTrend');
        }
    }

    public function donationByUserType(DashboardFilterRequest $request)
    {
        try {
            return JsonResponser::send(
                false,
                'Donations by user type retrieved.',
                $this->dashboardService->donationByUserType($request->validated())
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Dashboard\DashboardController@donationByUserType');
        }
    }

    public function donationByDonationType(DashboardFilterRequest $request)
    {
        try {
            return JsonResponser::send(
                false,
                'Donations by donation type retrieved.',
                $this->dashboardService->donationByDonationType($request->validated())
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Dashboard\DashboardController@donationByDonationType');
        }
    }

    public function donationByContributionTier(DashboardFilterRequest $request)
    {
        try {
            return JsonResponser::send(
                false,
                'Donations by contribution tier retrieved.',
                $this->dashboardService->donationByContributionTier($request->validated())
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Dashboard\DashboardController@donationByContributionTier');
        }
    }

    public function activeCampaigns(DashboardFilterRequest $request)
    {
        try {
            return JsonResponser::send(
                false,
                'Active campaigns retrieved.',
                $this->dashboardService->activeCampaigns($request->validated())
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Dashboard\DashboardController@activeCampaigns');
        }
    }
}
