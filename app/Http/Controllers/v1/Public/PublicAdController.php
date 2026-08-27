<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAdResource;
use App\Responser\JsonResponser;
use App\Services\Public\PublicAdService;

class PublicAdController extends Controller
{
    public function __construct(
        private readonly PublicAdService $publicAdService,
    ) {}

    public function index()
    {
        try {
            $ads = $this->publicAdService->listVisible();

            return JsonResponser::send(false, 'Ads retrieved.', [
                'ads_transition_seconds' => $this->publicAdService->transitionSeconds(),
                'ads' => PublicAdResource::collection($ads)->resolve(),
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicAdController@index');
        }
    }
}
