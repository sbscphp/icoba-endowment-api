<?php

namespace App\Services\Public;

use App\Models\CampaignUpdateReport;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class CampaignUpdateReportPdfService
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(CampaignUpdateReport $report): array
    {
        $logoPath = public_path('assets/logo/quiva-logo-black.png');
        $logoBase64 = File::exists($logoPath)
            ? 'data:image/png;base64,'.base64_encode(File::get($logoPath))
            : null;

        return [
            'report' => $report,
            'campaign' => $report->campaign,
            'logoBase64' => $logoBase64,
            'generatedAt' => now((string) config('app.timezone')),
        ];
    }

    public function streamDownload(CampaignUpdateReport $report): Response
    {
        $filename = 'campaign-report-'.$report->report_id.'.pdf';

        return new Response($this->renderPdf($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function renderPdf(CampaignUpdateReport $report): string
    {
        $html = view('pdf.campaign-update-report', $this->viewData($report))->render();

        $options = new Options;
        $options->set('isPhpEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'sans-serif');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
