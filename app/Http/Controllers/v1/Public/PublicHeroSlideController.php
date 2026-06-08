<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicHeroSlideResource;
use App\Responser\JsonResponser;
use App\Services\Public\PublicHeroSlideService;

class PublicHeroSlideController extends Controller
{
    public function __construct(
        private readonly PublicHeroSlideService $publicHeroSlideService,
    ) {}

    public function index()
    {
        try {
            $slide = $this->publicHeroSlideService->listActive();

            return JsonResponser::send(
                false,
                'Hero slide retrieved.',
                $slide !== null ? PublicHeroSlideResource::make($slide)->resolve() : null
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicHeroSlideController@index');
        }
    }
}
