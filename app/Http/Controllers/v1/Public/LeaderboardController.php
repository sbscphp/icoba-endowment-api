<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\LeaderboardRequest;
use App\Http\Requests\Public\TopSetsRequest;
use App\Responser\JsonResponser;
use App\Services\Public\LeaderboardService;

class LeaderboardController extends Controller
{
    public function __construct(
        private readonly LeaderboardService $leaderboardService,
    ) {}

    public function leaderboard(LeaderboardRequest $request)
    {
        try {
            $paginator = $this->leaderboardService->donorsLeaderboard($request->validated());

            return JsonResponser::send(false, 'Leaderboard retrieved.', $paginator);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\LeaderboardController@leaderboard');
        }
    }

    public function sets(LeaderboardRequest $request)
    {
        try {
            $paginator = $this->leaderboardService->setsLeaderboard($request->validated());

            return JsonResponser::send(false, 'Set leaderboard retrieved.', $paginator);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\LeaderboardController@sets');
        }
    }

    public function topSets(TopSetsRequest $request)
    {
        try {
            $payload = $this->leaderboardService->topSets($request->validated());

            return JsonResponser::send(false, 'Top sets retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\LeaderboardController@topSets');
        }
    }

    public function recentDonations(LeaderboardRequest $request)
    {
        try {
            $campaignUuid = (string) $request->query('campaign_uuid', '');
            $limit = max(1, min((int) $request->query('limit', 20), 50));
            $rows = $this->leaderboardService->recentDonations($campaignUuid, $limit);

            return JsonResponser::send(false, 'Recent donations retrieved.', $rows->all());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\LeaderboardController@recentDonations');
        }
    }

    public function fundProgressList(LeaderboardRequest $request)
    {
        try {
            $payload = $this->leaderboardService->campaignsFundProgressList($request->validated());

            return JsonResponser::send(false, 'Fund progress list retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\LeaderboardController@fundProgressList');
        }
    }

    public function fundProgress(LeaderboardRequest $request, string $campaignUuid)
    {
        try {
            $payload = $this->leaderboardService->campaignFundProgress($campaignUuid, $request->validated());

            return JsonResponser::send(false, 'Fund progress retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\LeaderboardController@fundProgress');
        }
    }
}
