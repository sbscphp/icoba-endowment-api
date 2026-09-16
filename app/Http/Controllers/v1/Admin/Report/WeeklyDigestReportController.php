<?php

namespace App\Http\Controllers\v1\Admin\Report;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Report\WeeklyDigestReportRequest;
use App\Responser\JsonResponser;
use App\Services\Admin\Report\WeeklyDigest\WeeklyDigestDocumentRenderer;
use App\Services\Admin\Report\WeeklyDigest\WeeklyDigestReportBuilder;
use App\Services\Admin\Report\WeeklyDigest\WeeklyDigestReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On-demand version of the emailed weekly digest. Same builder, so the numbers
 * always match what admins received by email.
 */
class WeeklyDigestReportController extends Controller
{
    public function __construct(
        private readonly WeeklyDigestReportBuilder $builder,
        private readonly WeeklyDigestDocumentRenderer $renderer,
    ) {}

    public function show(WeeklyDigestReportRequest $request)
    {
        try {
            $validated = $request->validated();
            $periodEnd = WeeklyDigestReportService::resolvePeriodEnd($validated['date'] ?? null);
            $export = $validated['export'] ?? null;

            if ($export !== null && $export !== '') {
                $this->ensureCanExport($request);
            }

            $report = $this->builder->build($periodEnd);

            return match ($export) {
                'pdf' => new Response($this->renderer->renderPdf($report), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.WeeklyDigestReportService::pdfFilename($report['period_start'], $report['period_end']).'"',
                ]),
                'csv' => new Response($this->renderer->renderOverdueCsv($report), 200, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="'.WeeklyDigestReportService::csvFilename($report['period_end']).'"',
                ]),
                default => JsonResponser::send(false, 'Weekly digest report generated.', $report),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Report\WeeklyDigestReportController@show');
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
