<?php

namespace App\Http\Controllers\v1\Admin\TierConfiguration;

use App\Enums\TierBenefit;
use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DateRangeStatsRequest;
use App\Http\Requests\Admin\Tier\CreateTierConfigurationRequest;
use App\Http\Requests\Admin\Tier\TierListRequest;
use App\Http\Requests\Admin\Tier\UpdateTierConfigurationRequest;
use App\Http\Resources\TierConfigurationListResource;
use App\Http\Resources\TierConfigurationResource;
use App\Models\TierConfiguration;
use App\Responser\JsonResponser;
use App\Services\Admin\TierConfiguration\TierConfigurationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TierConfigurationController extends Controller
{
    public function __construct(
        private readonly TierConfigurationService $tierConfigurationService,
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    public function stats(DateRangeStatsRequest $request)
    {
        try {
            return JsonResponser::send(false, 'Tier stats retrieved.', $this->tierConfigurationService->stats($request->validated()));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@stats');
        }
    }

    public function index(TierListRequest $request)
    {
        try {
            $listing = $request->validated();
            $export = $listing['export'] ?? null;

            return match ($export) {
                'csv' => $this->respondListCsv($listing),
                'pdf' => $this->respondListPdf($listing),
                default => $this->respondListPaginated($listing),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@index');
        }
    }

    public function store(CreateTierConfigurationRequest $request)
    {
        try {
            $tier = $this->tierConfigurationService->create($request->validated());

            return JsonResponser::send(false, 'Tier configuration created successfully.', TierConfigurationResource::make($tier)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@store');
        }
    }

    public function show(string $tierId)
    {
        try {
            $tier = $this->tierConfigurationService->findTier($tierId);
            $tier->loadCount('certificateTemplates as templates_count');

            return JsonResponser::send(false, 'Tier configuration retrieved.', TierConfigurationResource::make($tier)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@show');
        }
    }

    public function update(UpdateTierConfigurationRequest $request, string $tierId)
    {
        try {
            $tier = $this->tierConfigurationService->update($tierId, $request->validated());
            $tier->loadCount('certificateTemplates as templates_count');

            return JsonResponser::send(false, 'Tier configuration updated.', TierConfigurationResource::make($tier)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@update');
        }
    }

    public function toggleStatus(string $tierId)
    {
        try {
            $tier = $this->tierConfigurationService->toggleActiveStatus($tierId);
            $tier->loadCount('certificateTemplates as templates_count');
            $message = (bool) $tier->is_active ? 'Tier configuration activated.' : 'Tier configuration deactivated.';

            return JsonResponser::send(false, $message, TierConfigurationResource::make($tier)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@toggleStatus');
        }
    }

    public function destroy(string $tierId)
    {
        try {
            $result = $this->tierConfigurationService->delete($tierId);
            $templatesCount = $result['templates_count'];
            if ($templatesCount > 0) {
                return JsonResponser::send(true, 'Tier cannot be deleted because it is linked to one or more certificate templates.', [
                    'templates_count' => $templatesCount,
                ], 422);
            }

            return JsonResponser::send(false, 'Tier configuration deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@destroy');
        }
    }

    public function dropdown(?string $status = null)
    {
        try {
            $tiers = $this->tierConfigurationService->dropdown($status ?? 'active');
            $payload = $tiers->map(fn ($tier) => [
                'tier_id' => $tier->uuid,
                'name' => $tier->name,
                'tier_badge_url' => $tier->tier_badge_url,
                'is_active' => (bool) $tier->is_active,
            ])->values()->all();

            return JsonResponser::send(false, 'Tier dropdown retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@dropdown');
        }
    }

    public function benefitOptions()
    {
        try {
            return JsonResponser::send(false, 'Tier benefit options retrieved.', TierBenefit::options());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@benefitOptions');
        }
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListPaginated(array $listing)
    {
        $paginator = $this->tierConfigurationService->list($listing);

        return JsonResponser::send(false, 'Tier configurations retrieved.', $this->paginatedPayload($paginator, TierConfigurationListResource::class));
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListCsv(array $listing): StreamedResponse
    {
        [$collection, $truncated] = $this->tierConfigurationService->exportCollection($listing);
        $filename = 'tier-configurations-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($collection): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Tier ID',
                'Name',
                'Min amount',
                'Max amount',
                'Benefits count',
                'Templates count',
                'Sort order',
                'Status',
                'Last updated',
            ]);

            foreach ($collection as $tier) {
                /** @var TierConfiguration $tier */
                fputcsv($out, $this->tabularRow($tier));
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
    private function respondListPdf(array $listing)
    {
        [$collection, $truncated] = $this->tierConfigurationService->exportCollection($listing);
        $filename = 'tier-configurations-'.now()->format('Y-m-d-His').'.pdf';
        $periodStart = ! empty($listing['start_date']) ? (string) $listing['start_date'] : 'All dates';
        $periodEnd = ! empty($listing['end_date']) ? (string) $listing['end_date'] : 'All dates';

        $headings = [
            'Tier ID',
            'Name',
            'Min amount',
            'Max amount',
            'Benefits',
            'Templates',
            'Sort',
            'Status',
            'Updated',
        ];

        $rows = $collection->map(fn (TierConfiguration $tier): array => [
            $tier->uuid,
            $tier->name,
            $tier->min_amount !== null ? (string) $tier->min_amount : '',
            $tier->max_amount !== null ? (string) $tier->max_amount : '',
            (string) (is_array($tier->benefits) ? count($tier->benefits) : 0),
            (string) (int) ($tier->templates_count ?? 0),
            (string) (int) $tier->sort_order,
            $tier->is_active ? 'active' : 'inactive',
            $tier->updated_at?->format('Y-m-d H:i') ?? '',
        ]);

        return $this->pdfReportHelper->download(
            rows: $rows,
            headings: $headings,
            title: 'Tier configurations',
            filename: $filename,
            orientation: 'landscape',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            generatedAt: now((string) config('app.timezone')),
            truncated: $truncated,
            includedRows: $rows->count(),
        );
    }

    /**
     * @return list<int|string|null>
     */
    private function tabularRow(TierConfiguration $tier): array
    {
        return [
            $tier->uuid,
            $tier->name,
            $tier->min_amount !== null ? (string) $tier->min_amount : '',
            $tier->max_amount !== null ? (string) $tier->max_amount : '',
            is_array($tier->benefits) ? count($tier->benefits) : 0,
            (int) ($tier->templates_count ?? 0),
            (int) $tier->sort_order,
            $tier->is_active ? 'active' : 'inactive',
            $tier->updated_at?->toIso8601String() ?? '',
        ];
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
