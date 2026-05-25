<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicTierResource;
use App\Responser\JsonResponser;
use App\Services\Public\PublicTierService;

class PublicTierController extends Controller
{
    public function __construct(
        private readonly PublicTierService $publicTierService,
    ) {}

    public function index()
    {
        try {
            $tiers = $this->publicTierService->listActive();

            return JsonResponser::send(
                false,
                'Tiers retrieved.',
                PublicTierResource::collection($tiers)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicTierController@index');
        }
    }
}
