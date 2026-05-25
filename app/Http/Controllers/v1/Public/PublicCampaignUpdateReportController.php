<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\PublicCampaignUpdateReportListRequest;
use App\Http\Resources\CampaignUpdateReportResource;
use App\Http\Resources\PublicCampaignUpdateReportListResource;
use App\Responser\JsonResponser;
use App\Services\Admin\Campaign\CampaignUpdateReportService;
use App\Services\Public\CampaignUpdateReportPdfService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicCampaignUpdateReportController extends Controller
{
    public function __construct(
        private readonly CampaignUpdateReportService $campaignUpdateReportService,
        private readonly CampaignUpdateReportPdfService $campaignUpdateReportPdfService,
    ) {}

    public function index(PublicCampaignUpdateReportListRequest $request)
    {
        try {
            $paginator = $this->campaignUpdateReportService->listActive($request->validated());

            return JsonResponser::send(
                false,
                'Campaign update reports retrieved.',
                $this->paginatedPayload($paginator, PublicCampaignUpdateReportListResource::class)
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicCampaignUpdateReportController@index');
        }
    }

    public function show(string $reportId)
    {
        try {
            $report = $this->campaignUpdateReportService->findActiveReport($reportId);

            return JsonResponser::send(
                false,
                'Campaign update report retrieved.',
                CampaignUpdateReportResource::make($report)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicCampaignUpdateReportController@show');
        }
    }

    public function download(string $reportId): Response
    {
        try {
            $report = $this->campaignUpdateReportService->findActiveReport($reportId);

            return $this->campaignUpdateReportPdfService->streamDownload($report);
        } catch (\Throwable $th) {
            if ($th instanceof HttpException) {
                throw $th;
            }

            /** @var JsonResponse $resp */
            $resp = GeneralHelper::handleControllerThrowable($th, 'Public\PublicCampaignUpdateReportController@download');

            return $resp;
        }
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     * @return array<string, mixed>
     */
    private function paginatedPayload(LengthAwarePaginator $paginator, string $resourceClass): array
    {
        $payload = $paginator->toArray();
        /** @var AnonymousResourceCollection $resource */
        $resource = $resourceClass::collection($paginator);
        $payload['data'] = $resource->resolve();

        return $payload;
    }
}
