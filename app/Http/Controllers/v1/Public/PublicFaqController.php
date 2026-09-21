<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicFaqResource;
use App\Responser\JsonResponser;
use App\Services\Public\PublicFaqService;

class PublicFaqController extends Controller
{
    public function __construct(
        private readonly PublicFaqService $publicFaqService,
    ) {}

    public function index()
    {
        try {
            $faqs = $this->publicFaqService->listActive();

            return JsonResponser::send(
                false,
                'FAQs retrieved.',
                PublicFaqResource::collection($faqs)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicFaqController@index');
        }
    }
}
