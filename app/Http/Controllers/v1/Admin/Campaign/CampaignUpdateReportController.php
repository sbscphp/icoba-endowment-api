<?php

namespace App\Http\Controllers\v1\Admin\Campaign;

use App\Enums\CampaignUpdateReportStatus;
use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Campaign\CampaignUpdateReportListRequest;
use App\Http\Requests\Admin\Campaign\CreateCampaignUpdateReportRequest;
use App\Http\Requests\Admin\Campaign\UpdateCampaignUpdateReportRequest;
use App\Http\Resources\CampaignUpdateReportListResource;
use App\Http\Resources\CampaignUpdateReportResource;
use App\Models\CampaignUpdateReport;
use App\Responser\JsonResponser;
use App\Services\Admin\Campaign\CampaignUpdateReportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignUpdateReportController extends Controller
{
    public function __construct(
        private readonly CampaignUpdateReportService $campaignUpdateReportService,
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    public function index(CampaignUpdateReportListRequest $request, string $campaignId)
    {
        try {
            $listing = $request->validated();
            $export = $listing['export'] ?? null;

            return match ($export) {
                'csv' => $this->respondListCsv($campaignId, $listing),
                'pdf' => $this->respondListPdf($campaignId, $listing),
                default => $this->respondListPaginated($campaignId, $listing),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignUpdateReportController@index');
        }
    }

    public function store(CreateCampaignUpdateReportRequest $request, string $campaignId)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $report = $this->campaignUpdateReportService->create($campaignId, $request->validated(), $adminUuid);

            return JsonResponser::send(
                false,
                'Campaign update report created successfully.',
                CampaignUpdateReportResource::make($report)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignUpdateReportController@store');
        }
    }

    public function show(string $campaignId, string $reportId)
    {
        try {
            $report = $this->campaignUpdateReportService->findReport($campaignId, $reportId);
            $report->load('campaign');

            return JsonResponser::send(
                false,
                'Campaign update report retrieved.',
                CampaignUpdateReportResource::make($report)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignUpdateReportController@show');
        }
    }

    public function update(UpdateCampaignUpdateReportRequest $request, string $campaignId, string $reportId)
    {
        try {
            $report = $this->campaignUpdateReportService->update($campaignId, $reportId, $request->validated());
            $report->load('campaign');

            return JsonResponser::send(
                false,
                'Campaign update report updated.',
                CampaignUpdateReportResource::make($report)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignUpdateReportController@update');
        }
    }

    public function toggleStatus(string $campaignId, string $reportId)
    {
        try {
            $report = $this->campaignUpdateReportService->toggleActiveStatus($campaignId, $reportId);
            $report->load('campaign');
            $message = (bool) $report->is_active
                ? 'Campaign update report activated.'
                : 'Campaign update report deactivated.';

            return JsonResponser::send(
                false,
                $message,
                CampaignUpdateReportResource::make($report)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignUpdateReportController@toggleStatus');
        }
    }

    public function destroy(string $campaignId, string $reportId)
    {
        try {
            $this->campaignUpdateReportService->delete($campaignId, $reportId);

            return JsonResponser::send(false, 'Campaign update report deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignUpdateReportController@destroy');
        }
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListPaginated(string $campaignId, array $listing)
    {
        $paginator = $this->campaignUpdateReportService->list($campaignId, $listing);

        return JsonResponser::send(
            false,
            'Campaign update reports retrieved.',
            $this->paginatedPayload($paginator, CampaignUpdateReportListResource::class)
        );
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListCsv(string $campaignId, array $listing): StreamedResponse
    {
        [$collection, $truncated] = $this->campaignUpdateReportService->exportCollection($campaignId, $listing);
        $filename = 'campaign-update-reports-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($collection): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Report ID', 'Report Name', 'Date Created', 'Last Updated', 'Status']);
            foreach ($collection as $report) {
                /** @var CampaignUpdateReport $report */
                fputcsv($out, [
                    $report->report_id,
                    $report->name,
                    $report->created_at?->format('d/m/Y') ?? '',
                    $report->updated_at?->format('d/m/Y') ?? '',
                    CampaignUpdateReportStatus::fromIsActive((bool) $report->is_active)->value,
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Truncated' => $truncated ? '1' : '0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListPdf(string $campaignId, array $listing)
    {
        [$collection, $truncated] = $this->campaignUpdateReportService->exportCollection($campaignId, $listing);
        $filename = 'campaign-update-reports-'.now()->format('Y-m-d-His').'.pdf';
        $periodStart = ! empty($listing['start_date']) ? (string) $listing['start_date'] : 'All dates';
        $periodEnd = ! empty($listing['end_date']) ? (string) $listing['end_date'] : 'All dates';

        $headings = ['Report ID', 'Report Name', 'Date Created', 'Last Updated', 'Status'];
        $rows = $collection->map(fn (CampaignUpdateReport $report): array => [
            $report->report_id,
            $report->name,
            $report->created_at?->format('d/m/Y') ?? '',
            $report->updated_at?->format('d/m/Y') ?? '',
            CampaignUpdateReportStatus::fromIsActive((bool) $report->is_active)->value,
        ]);

        return $this->pdfReportHelper->download(
            $rows,
            $headings,
            'Campaign Update Reports',
            $filename,
            'landscape',
            $periodStart,
            $periodEnd,
            null,
            $truncated,
            $rows->count()
        );
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
