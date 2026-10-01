<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Responser\JsonResponser;
use App\Services\Public\PublicAboutSectionService;

class PublicAboutSectionController extends Controller
{
    public function __construct(
        private readonly PublicAboutSectionService $publicAboutSectionService,
    ) {}

    public function index()
    {
        try {
            return JsonResponser::send(false, 'Sections retrieved.', $this->publicAboutSectionService->liveContent());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicAboutSectionController@index');
        }
    }
}
