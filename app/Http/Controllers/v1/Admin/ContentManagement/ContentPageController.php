<?php

namespace App\Http\Controllers\v1\Admin\ContentManagement;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Responser\JsonResponser;
use App\Services\Admin\ContentManagement\ContentPageService;

class ContentPageController extends Controller
{
    public function __construct(
        private readonly ContentPageService $contentPageService,
    ) {}

    public function index()
    {
        try {
            return JsonResponser::send(
                false,
                'Content pages retrieved.',
                $this->contentPageService->listPages()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\ContentPageController@index');
        }
    }
}
