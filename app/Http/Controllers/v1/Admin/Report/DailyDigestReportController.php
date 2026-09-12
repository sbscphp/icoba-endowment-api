<?php

namespace App\Http\Controllers\v1\Admin\Report;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Report\DailyDigestReportRequest;
use App\Responser\JsonResponser;
use App\Services\Admin\Report\DailyDigest\DailyDigestDocumentRenderer;
use App\Services\Admin\Report\DailyDigest\DailyDigestReportBuilder;
use App\Services\Admin\Report\DailyDigest\DailyDigestReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On-demand version of the emailed daily digest. Same builder, so the numbers
 * always match what admins received by email.
 */
class DailyDigestReportController extends Controller
{
    public function __construct(
        private readonly DailyDigestReportBuilder $builder,
        private readonly DailyDigestDocumentRenderer $renderer,
    ) {}

    public function show(DailyDigestReportRequest $request)
    {
        try {
            $validated = $request->validated();
            $reportDate = DailyDigestReportService::resolveReportDate($validated['date'] ?? null);
            $export = $validated['export'] ?? null;

            if ($export !== null && $export !== '') {
                $this->ensureCanExport($request);
            }

            $report = $this->builder->build($reportDate);

            return match ($export) {
                'pdf' => new Response($this->renderer->renderPdf($report), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.DailyDigestReportService::pdfFilename($report['report_date']).'"',
                ]),
                'csv' => new Response($this->renderer->renderOverdueCsv($report), 200, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="'.DailyDigestReportService::csvFilename($report['report_date']).'"',
                ]),
                default => JsonResponser::send(false, 'Daily digest report generated.', $report),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Report\DailyDigestReportController@show');
        }
    }

    private function ensureCanExport(Request $request): void
    {
        $admin = $request->user();
        if ($admin === null || ! method_exists($admin, 'hasPermissionTo') || ! $admin->hasPermissionTo('reports.export')) {
            abort(403, 'You do not have permission to export reports.');
        }
    }
}
