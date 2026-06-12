<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Services\PublicDownload\PublicDocumentDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicDocumentDownloadController extends Controller
{
    public function __construct(
        private readonly PublicDocumentDownloadService $downloadService,
    ) {}

    public function download(Request $request, string $token): Response|JsonResponse
    {
        try {
            return $this->downloadService->streamByToken($token);
        } catch (\Throwable $th) {
            if ($th instanceof HttpException) {
                throw $th;
            }

            /** @var JsonResponse $resp */
            $resp = GeneralHelper::handleControllerThrowable($th, 'Public\PublicDocumentDownloadController@download');

            return $resp;
        }
    }
}
