<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Services\Recognition\CertificatePdfService;
use App\Services\Recognition\DonorRecognitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RecognitionDownloadController extends Controller
{
    public function __construct(
        private readonly DonorRecognitionService $recognitionService,
        private readonly CertificatePdfService $certificatePdfService,
    ) {}

    public function download(Request $request, string $recognitionNumber): Response
    {
        try {
            $recognition = $this->resolveAuthorizedRecognition($request, $recognitionNumber);

            return $this->certificatePdfService->streamCertificate($recognition);
        } catch (\Throwable $th) {
            if ($th instanceof HttpException) {
                throw $th;
            }

            /** @var JsonResponse $resp */
            $resp = GeneralHelper::handleControllerThrowable($th, 'Public\RecognitionDownloadController@download');

            return $resp;
        }
    }

    private function resolveAuthorizedRecognition(Request $request, string $recognitionNumber): \App\Models\DonorRecognition
    {
        $recognition = $this->recognitionService->resolveByRecognitionNumber($recognitionNumber);

        $token = trim((string) $request->query('token', ''));
        if ($token === '') {
            return $recognition;
        }

        $recognition = $this->recognitionService->ensureDownloadToken($recognition);

        if ($recognition->download_token === null || ! hash_equals((string) $recognition->download_token, $token)) {
            abort(403, 'Invalid recognition token.');
        }

        return $recognition;
    }
}
