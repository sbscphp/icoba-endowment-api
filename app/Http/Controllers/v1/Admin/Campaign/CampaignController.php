<?php

namespace App\Http\Controllers\v1\Admin\Campaign;

use App\Enums\AuditActionEnum;
use App\Enums\CampaignCategory;
use App\Enums\Currency;
use App\Enums\ModuleEnums;
use App\Enums\UserTypeEnum;
use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Campaign\CampaignLifecycleRequest;
use App\Http\Requests\Admin\Campaign\CampaignListRequest;
use App\Http\Requests\Admin\Campaign\CampaignReportRequest;
use App\Http\Requests\Admin\Campaign\CampaignStatsRequest;
use App\Http\Requests\Admin\Campaign\CampaignStatusLogListRequest;
use App\Http\Requests\Admin\Campaign\CreateCampaignRequest;
use App\Http\Requests\Admin\Campaign\ShowCampaignRequest;
use App\Http\Requests\Admin\Campaign\UpdateCampaignRequest;
use App\Http\Resources\CampaignListResource;
use App\Http\Resources\CampaignResource;
use App\Http\Resources\CampaignStatsResource;
use App\Http\Resources\CampaignStatusLogResource;
use App\Models\Admin;
use App\Models\Campaign;
use App\Responser\JsonResponser;
use App\Services\Admin\Campaign\CampaignService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    public function index(CampaignListRequest $request)
    {
        try {
            $listing = $request->validated();
            $export = $listing['export'] ?? null;

            return match ($export) {
                'csv' => $this->respondCampaignListCsv($listing),
                'pdf' => $this->respondCampaignListPdf($listing),
                default => $this->respondCampaignListPaginated($listing),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@index');
        }
    }

    public function stats(CampaignStatsRequest $request)
    {
        try {
            $v = $request->validated();
            $payload = $this->campaignService->stats(
                $v['start_date'] ?? null,
                $v['end_date'] ?? null,
            );

            return JsonResponser::send(false, 'Campaign stats retrieved.', CampaignStatsResource::make($payload)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@stats');
        }
    }

    public function categoryOptions()
    {
        try {
            $payload = collect(CampaignCategory::cases())->map(fn (CampaignCategory $c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ])->values()->all();

            return JsonResponser::send(false, 'Campaign categories retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@categoryOptions');
        }
    }

    public function currencyOptions()
    {
        try {
            $payload = collect(Currency::cases())->map(fn (Currency $c) => [
                'value' => $c->value,
                'symbol' => $c->symbol(),
            ])->values()->all();

            return JsonResponser::send(false, 'Currencies retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@currencyOptions');
        }
    }

    public function applicableOptions(?string $status = null)
    {
        try {
            $sets = $this->campaignService->applicableOptions($status ?? 'all');
            $payload = $sets->map(fn ($set) => [
                'graduation_set_id' => $set->uuid,
                'name' => $set->name,
                'set_number' => $set->set_number,
            ])->values()->all();

            return JsonResponser::send(false, 'Applicable graduation sets retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@applicableOptions');
        }
    }

    public function dropdown(?string $status = null)
    {
        try {
            $campaigns = $this->campaignService->dropdown($status ?? 'all');
            $payload = $campaigns->map(fn (Campaign $c) => [
                'campaign_id' => $c->uuid,
                'public_campaign_code' => $c->campaign_id,
                'name' => $c->name,
                'status' => $c->status->value,
            ])->values()->all();

            return JsonResponser::send(false, 'Campaign dropdown retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@dropdown');
        }
    }

    public function store(CreateCampaignRequest $request)
    {
        try {
            $admin = $this->requireAdmin($request);
            $campaign = $this->campaignService->create($request->validated(), $admin, $request);

            return JsonResponser::send(false, 'Campaign created successfully.', $this->campaignResourcePayload($request, $campaign));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@store');
        }
    }

    public function show(ShowCampaignRequest $request, string $campaignId)
    {
        try {
            $this->requireAdmin($request);
            $campaign = $this->campaignService->findCampaign($campaignId);
            $campaign->loadMissing('graduationSets');
            $raiseFilters = $request->validated()['filters'] ?? [];

            return JsonResponser::send(false, 'Campaign retrieved.', $this->campaignResourcePayload($request, $campaign, $raiseFilters));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@show');
        }
    }

    public function update(UpdateCampaignRequest $request, string $campaignId)
    {
        try {
            $admin = $this->requireAdmin($request);
            $campaign = $this->campaignService->update($campaignId, $request->validated(), $admin, $request);

            return JsonResponser::send(false, 'Campaign updated.', $this->campaignResourcePayload($request, $campaign));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@update');
        }
    }

    public function destroy(Request $request, string $campaignId)
    {
        try {
            $admin = $this->requireAdmin($request);
            $result = $this->campaignService->delete($campaignId);
            if ((int) $result['blocked'] === 1) {
                return JsonResponser::send(
                    true,
                    'Campaign cannot be deleted.',
                    ['transactions_count' => $result['transactions_count'] ?? 0],
                    422
                );
            }

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::CAMPAIGN_DELETED,
                $request,
                $admin->uuid,
                ['campaign_uuid' => $result['uuid'] ?? $campaignId],
                'Campaign deleted.',
                Campaign::class,
                (string) ($result['uuid'] ?? $campaignId),
                ModuleEnums::campaign_management,
                200,
            );

            return JsonResponser::send(false, 'Campaign deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@destroy');
        }
    }

    public function statusLogs(CampaignStatusLogListRequest $request, string $campaignId)
    {
        try {
            $paginator = $this->campaignService->statusLogsPaginated($campaignId, $request->validated());

            return JsonResponser::send(
                false,
                'Campaign status logs retrieved.',
                $this->paginatedPayload($paginator, CampaignStatusLogResource::class)
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@statusLogs');
        }
    }

    public function report(CampaignReportRequest $request, string $campaignId)
    {
        try {
            $admin = $this->requireAdmin($request);

            return $this->campaignService->generateReport($campaignId, $admin, $request, $request->validated());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@report');
        }
    }

    public function transition(CampaignLifecycleRequest $request, string $campaignId)
    {
        try {
            $admin = $this->requireAdmin($request);
            $v = $request->validated();
            $reason = $v['reason'] ?? null;

            $campaign = match ($v['action']) {
                'activate' => $this->campaignService->activate($campaignId, $reason, $admin, $request),
                'pause' => $this->campaignService->pause($campaignId, $reason, $admin, $request),
                'resume' => $this->campaignService->resume($campaignId, $reason, $admin, $request),
                'deactivate' => $this->campaignService->deactivate($campaignId, $reason, $admin, $request),
            };

            $message = match ($v['action']) {
                'activate' => 'Campaign activated.',
                'pause' => 'Campaign paused.',
                'resume' => 'Campaign resumed.',
                'deactivate' => 'Campaign deactivated.',
            };

            return JsonResponser::send(false, $message, $this->campaignResourcePayload($request, $campaign));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Campaign\CampaignController@transition');
        }
    }

    private function requireAdmin(Request $request): Admin
    {
        $admin = $request->user();
        if (! $admin instanceof Admin) {
            abort(403, 'Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $raiseFilters  Subset of list filters, e.g. ['raised_currency' => 'USD']
     * @return array<string, mixed>
     */
    private function campaignResourcePayload(Request $request, Campaign $campaign, array $raiseFilters = []): array
    {
        if ($raiseFilters === []) {
            $c = strtoupper((string) $request->input('filters.raised_currency', ''));
            if ($c !== '' && in_array($c, Currency::values(), true)) {
                $raiseFilters = ['raised_currency' => $c];
            }
        }

        $this->campaignService->hydrateCampaignRaiseTotals($campaign, $raiseFilters);

        return CampaignResource::make($campaign)->resolve();
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondCampaignListPaginated(array $listing)
    {
        $paginator = $this->campaignService->list($listing);

        return JsonResponser::send(false, 'Campaigns retrieved.', $this->paginatedPayload($paginator, CampaignListResource::class));
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondCampaignListCsv(array $listing): StreamedResponse
    {
        [$collection, $truncated] = $this->campaignService->exportCollection($listing);
        $filename = 'campaigns-'.now()->format('Y-m-d-His').'.csv';
        $raisedCurrency = strtoupper((string) data_get($listing, 'filters.raised_currency', 'NGN'));
        if ($raisedCurrency === '') {
            $raisedCurrency = 'NGN';
        }
        $raisedHeader = $raisedCurrency === 'NGN' ? 'Raised NGN' : 'Raised ('.$raisedCurrency.')';

        return response()->streamDownload(function () use ($collection, $raisedHeader): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['UUID', 'Public code', 'Name', 'Status', 'Start', 'End', 'Target', 'Base currency', $raisedHeader]);
            foreach ($collection as $campaign) {
                /** @var Campaign $campaign */
                fputcsv($out, [
                    $campaign->uuid,
                    $campaign->campaign_id,
                    $campaign->name,
                    $campaign->status->value,
                    $campaign->start_date?->format('Y-m-d') ?? '',
                    $campaign->end_date?->format('Y-m-d') ?? '',
                    (string) $campaign->target_amount,
                    $campaign->base_currency,
                    (string) ($campaign->total_raised_filtered ?? '0'),
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
    private function respondCampaignListPdf(array $listing)
    {
        [$collection, $truncated] = $this->campaignService->exportCollection($listing);
        $filename = 'campaigns-'.now()->format('Y-m-d-His').'.pdf';
        $periodStart = ! empty($listing['start_date']) ? (string) $listing['start_date'] : 'All dates';
        $periodEnd = ! empty($listing['end_date']) ? (string) $listing['end_date'] : 'All dates';
        $raisedCurrency = strtoupper((string) data_get($listing, 'filters.raised_currency', 'NGN'));
        if ($raisedCurrency === '') {
            $raisedCurrency = 'NGN';
        }
        $raisedHeading = $raisedCurrency === 'NGN' ? 'Raised NGN' : 'Raised ('.$raisedCurrency.')';

        $headings = ['UUID', 'Code', 'Name', 'Status', 'Start', 'End', 'Target', 'Currency', $raisedHeading];
        $rows = $collection->map(fn (Campaign $c): array => [
            $c->uuid,
            $c->campaign_id,
            $c->name,
            $c->status->value,
            $c->start_date?->format('Y-m-d') ?? '',
            $c->end_date?->format('Y-m-d') ?? '',
            (string) $c->target_amount,
            $c->base_currency,
            (string) ($c->total_raised_filtered ?? '0'),
        ]);

        return $this->pdfReportHelper->download(
            rows: $rows,
            headings: $headings,
            title: 'Campaigns',
            filename: $filename,
            orientation: 'landscape',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            truncated: $truncated,
            includedRows: $rows->count(),
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
