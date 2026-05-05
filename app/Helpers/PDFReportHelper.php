<?php

namespace App\Helpers;

use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class PDFReportHelper
{
    public function download(
        Collection|array $rows,
        array $headings,
        string $title,
        string $filename = 'report.pdf',
        string $orientation = 'landscape',
        string $periodStart = 'All dates',
        string $periodEnd = 'All dates',
        ?CarbonInterface $generatedAt = null,
        bool $truncated = false,
        ?int $includedRows = null
    ): Response {
        $rows = $rows instanceof Collection ? $rows->toArray() : $rows;
        $orientation = strtolower($orientation) === 'portrait' ? 'portrait' : 'landscape';
        $generatedAt ??= now((string) config('app.timezone'));
        $logoPath = public_path('assets/logo/quiva-logo-black.png');
        $logoBase64 = File::exists($logoPath)
            ? 'data:image/png;base64,'.base64_encode(File::get($logoPath))
            : null;

        $html = view('pdf.admin-tabular-report', [
            'title' => $title,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'generatedAt' => $generatedAt,
            'logoBase64' => $logoBase64,
            'headers' => $headings,
            'rows' => $rows,
            'truncated' => $truncated,
            'includedRows' => $includedRows ?? count($rows),
        ])->render();

        $options = new Options;
        $options->set('isPhpEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'sans-serif');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
