<?php

namespace App\Http\Controllers\v1\Customer\Recognition;

use App\Enums\IssuedCertificateStatus;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerRecognitionListRequest;
use App\Http\Resources\Customer\CustomerRecognitionResource;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Customer\CustomerRecognitionService;
use App\Services\Recognition\CertificatePdfService;
use App\Services\Recognition\DonorRecognitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerRecognitionController extends Controller
{
    public function __construct(
        private readonly CustomerRecognitionService $customerRecognitionService,
        private readonly DonorRecognitionService $recognitionService,
        private readonly CertificatePdfService $certificatePdfService,
    ) {}

    public function index(CustomerRecognitionListRequest $request): JsonResponse|Response
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(401);
            }

            $validated = $request->validated();

            if ($request->boolean('download')) {
                $recognition = $this->recognitionService->resolveOwnedRecognition(
                    $user,
                    (string) $validated['recognition_uuid'],
                );

                if ($recognition->status === IssuedCertificateStatus::REVOKED) {
                    throw new ApiException('This certificate has been revoked and is no longer available for download.', 403);
                }

                return $this->certificatePdfService->streamCertificate($recognition);
            }

            $paginator = $this->customerRecognitionService->listForUser($user, $validated);

            return JsonResponser::send(false, 'Recognitions retrieved.', [
                ...$paginator->toArray(),
                'data' => CustomerRecognitionResource::collection($paginator->items())->resolve(),
            ]);
        } catch (\Throwable $th) {
            if ($th instanceof HttpException) {
                throw $th;
            }

            if ($request->boolean('download')) {
                /** @var JsonResponse $resp */
                $resp = GeneralHelper::handleControllerThrowable($th, 'Customer\Recognition\CustomerRecognitionController@index');

                return $resp;
            }

            return GeneralHelper::handleControllerThrowable($th, 'Customer\Recognition\CustomerRecognitionController@index');
        }
    }

    public function download(Request $request, string $recognitionUuid): Response|JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(401);
            }

            $recognition = $this->recognitionService->resolveOwnedRecognition($user, $recognitionUuid);

            if ($recognition->status === IssuedCertificateStatus::REVOKED) {
                throw new ApiException('This certificate has been revoked and is no longer available for download.', 403);
            }

            return $this->certificatePdfService->streamCertificate($recognition);
        } catch (\Throwable $th) {
            if ($th instanceof HttpException) {
                throw $th;
            }

            /** @var JsonResponse $resp */
            $resp = GeneralHelper::handleControllerThrowable($th, 'Customer\Recognition\CustomerRecognitionController@download');

            return $resp;
        }
    }
}
