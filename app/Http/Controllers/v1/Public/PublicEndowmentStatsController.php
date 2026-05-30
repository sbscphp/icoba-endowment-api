<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Responser\JsonResponser;
use App\Services\Public\PublicEndowmentStatsService;

class PublicEndowmentStatsController extends Controller
{
    public function __construct(
        private readonly PublicEndowmentStatsService $publicEndowmentStatsService,
    ) {}

    public function index()
    {
        try {
            return JsonResponser::send(
                false,
                'Endowment stats retrieved.',
                $this->publicEndowmentStatsService->stats()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicEndowmentStatsController@index');
        }
    }
}
