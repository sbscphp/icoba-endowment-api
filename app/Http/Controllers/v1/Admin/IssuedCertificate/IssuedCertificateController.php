<?php

namespace App\Http\Controllers\v1\Admin\IssuedCertificate;

use App\Enums\CertificatePreviewFormat;
use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DateRangeStatsRequest;
use App\Http\Requests\Admin\IssuedCertificate\IssuedCertificateListRequest;
use App\Http\Requests\Admin\IssuedCertificate\PreviewIssuedCertificateRequest;
use App\Http\Requests\Admin\IssuedCertificate\ReissueIssuedCertificateRequest;
use App\Http\Resources\IssuedCertificateListResource;
use App\Http\Resources\IssuedCertificateResource;
use App\Models\DonorRecognition;
use App\Responser\JsonResponser;
use App\Services\Admin\IssuedCertificate\IssuedCertificateService;
use App\Services\Recognition\CertificatePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IssuedCertificateController extends Controller
{
    public function __construct(
        private readonly IssuedCertificateService $issuedCertificateService,
        private readonly CertificatePdfService $certificatePdfService,
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    public function stats(DateRangeStatsRequest $request)
    {
        try {
            return JsonResponser::send(
                false,
                'Issued certificate stats retrieved.',
                $this->issuedCertificateService->stats($request->validated()),
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\IssuedCertificate\IssuedCertificateController@stats');
        }
    }

    public function index(IssuedCertificateListRequest $request)
    {
        try {
            $listing = $request->validated();
            $export = $listing['export'] ?? null;

            if ($export !== null && $export !== '') {
                $this->ensureCanExport($request);
            }

            return match ($export) {
                'csv' => $this->respondListCsv($listing),
                'pdf' => $this->respondListPdf($listing),
                default => $this->respondListPaginated($listing),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\IssuedCertificate\IssuedCertificateController@index');
        }
    }

    public function show(string $recognitionId)
    {
        try {
            $recognition = $this->issuedCertificateService->findRecognition($recognitionId);

            return JsonResponser::send(
                false,
                'Issued certificate retrieved.',
                IssuedCertificateResource::make($recognition)->resolve(),
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\IssuedCertificate\IssuedCertificateController@show');
        }
    }

    public function preview(PreviewIssuedCertificateRequest $request, string $recognitionId)
    {
        try {
            $recognition = $this->issuedCertificateService->findRecognition($recognitionId);
            $format = CertificatePreviewFormat::tryFromRequest($request->validated()['format'] ?? null);

            return $this->certificatePdfService->streamCertificateByFormat($recognition, $format);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\IssuedCertificate\IssuedCertificateController@preview');
        }
    }

    public function revoke(string $recognitionId)
    {
        try {
            $recognition = $this->issuedCertificateService->revoke($recognitionId);

            return JsonResponser::send(
                false,
                'Issued certificate revoked.',
                IssuedCertificateResource::make($recognition)->resolve(),
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\IssuedCertificate\IssuedCertificateController@revoke');
        }
    }

    public function reissue(ReissueIssuedCertificateRequest $request, string $recognitionId)
    {
        try {
            $recognition = $this->issuedCertificateService->reissue($recognitionId, $request->validated());

            return JsonResponser::send(
                false,
                'Issued certificate reissued.',
                IssuedCertificateResource::make($recognition)->resolve(),
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\IssuedCertificate\IssuedCertificateController@reissue');
        }
    }

    private function ensureCanExport(Request $request): void
    {
        $admin = $request->user();
        if ($admin === null || ! method_exists($admin, 'hasPermissionTo') || ! $admin->hasPermissionTo('issued_certificates.export')) {
            abort(403, 'You do not have permission to export issued certificates.');
        }
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListPaginated(array $listing)
    {
        $paginator = $this->issuedCertificateService->list($listing);

        return JsonResponser::send(
            false,
            'Issued certificates retrieved.',
            $this->paginatedPayload($paginator, IssuedCertificateListResource::class),
        );
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListCsv(array $listing): StreamedResponse
    {
        [$collection, $truncated] = $this->issuedCertificateService->exportCollection($listing);
        $filename = 'issued-certificates-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($collection): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Reference ID',
                'Issue date',
                'Donor name',
                'Donor email',
                'Initial amount',
                'Initial currency',
                'Cumulative amount (NGN)',
                'Tier',
                'Paid into',
                'Status',
            ]);
            foreach ($collection as $recognition) {
                /** @var DonorRecognition $recognition */
                $recognition->loadMissing('tier:uuid,name', 'triggerTransaction:uuid,gateway');
                $gateway = $recognition->triggerTransaction?->gateway;
                fputcsv($out, [
                    $recognition->recognition_number,
                    $recognition->issued_at?->toIso8601String() ?? '',
                    $recognition->awardee_name,
                    (string) ($recognition->donor_email ?? ''),
                    $recognition->initial_amount !== null ? (string) $recognition->initial_amount : '',
                    (string) ($recognition->initial_currency ?? ''),
                    (string) $recognition->cumulative_amount_ngn,
                    $recognition->tier?->name ?? '',
                    IssuedCertificateService::paidIntoLabel(is_string($gateway) ? $gateway : null) ?? '',
                    $recognition->status instanceof \BackedEnum ? $recognition->status->value : (string) $recognition->status,
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
    private function respondListPdf(array $listing)
    {
        [$collection, $truncated] = $this->issuedCertificateService->exportCollection($listing);
        $collection->loadMissing('tier:uuid,name', 'triggerTransaction:uuid,gateway');
        $filename = 'issued-certificates-'.now()->format('Y-m-d-His').'.pdf';
        $periodStart = ! empty($listing['start_date']) ? (string) $listing['start_date'] : 'All dates';
        $periodEnd = ! empty($listing['end_date']) ? (string) $listing['end_date'] : 'All dates';

        $headings = ['Reference ID', 'Issue date', 'Donor', 'Tier', 'Amount (NGN)', 'Paid into', 'Status'];
        $rows = $collection->map(fn (DonorRecognition $recognition): array => [
            $recognition->recognition_number,
            $recognition->issued_at?->format('Y-m-d H:i') ?? '',
            $recognition->awardee_name,
            $recognition->tier?->name ?? '',
            (string) $recognition->cumulative_amount_ngn,
            IssuedCertificateService::paidIntoLabel(
                is_string($recognition->triggerTransaction?->gateway) ? $recognition->triggerTransaction->gateway : null
            ) ?? '',
            $recognition->status instanceof \BackedEnum ? $recognition->status->value : (string) $recognition->status,
        ]);

        return $this->pdfReportHelper->download(
            rows: $rows,
            headings: $headings,
            title: 'Issued Certificates',
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
