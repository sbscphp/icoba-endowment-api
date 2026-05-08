<?php

namespace App\Http\Controllers\v1\Admin\Report;

use App\Enums\ReportType;
use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Report\GenerateReportRequest;
use App\Responser\JsonResponser;
use App\Services\Admin\Report\ReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    public function reportTypes()
    {
        try {
            return JsonResponser::send(false, 'Report types retrieved.', $this->reportService->reportTypeOptions());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Report\ReportController@reportTypes');
        }
    }

    public function generate(GenerateReportRequest $request)
    {
        try {
            $validated = $request->validated();
            $export = $validated['export'] ?? null;
            if ($export !== null && $export !== '') {
                $this->ensureCanExport($request);
            }

            return match ($export) {
                'csv' => $this->respondCsv($validated),
                'pdf' => $this->respondPdf($validated),
                default => $this->respondPaginated($validated),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Report\ReportController@generate');
        }
    }

    private function ensureCanExport(Request $request): void
    {
        $admin = $request->user();
        if ($admin === null || ! method_exists($admin, 'hasPermissionTo') || ! $admin->hasPermissionTo('reports.export')) {
            abort(403, 'You do not have permission to export reports.');
        }
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    private function respondPaginated(array $validated)
    {
        $result = $this->reportService->paginated($validated);
        $payload = $result['paginator']->toArray();
        $payload['headers'] = $result['headers'];

        return JsonResponser::send(false, 'Report generated successfully.', $payload);
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    private function respondCsv(array $validated): StreamedResponse
    {
        $result = $this->reportService->exportRows($validated);
        $type = ReportType::from((string) $validated['report_type']);
        $filename = $type->value.'-report-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($result): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $result['headers']);
            foreach ($result['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Truncated' => $result['truncated'] ? '1' : '0',
        ]);
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    private function respondPdf(array $validated)
    {
        $result = $this->reportService->exportRows($validated);
        $type = ReportType::from((string) $validated['report_type']);
        $filename = $type->value.'-report-'.now()->format('Y-m-d-His').'.pdf';
        $periodStart = ! empty($validated['start_date']) ? (string) $validated['start_date'] : 'All dates';
        $periodEnd = ! empty($validated['end_date']) ? (string) $validated['end_date'] : 'All dates';

        return $this->pdfReportHelper->download(
            rows: $result['rows'],
            headings: $result['headers'],
            title: $type->label().' report',
            filename: $filename,
            orientation: 'landscape',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            truncated: $result['truncated'],
            includedRows: count($result['rows']),
        );
    }
}
