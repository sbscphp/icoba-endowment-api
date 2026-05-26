<?php

namespace App\Services\Recognition;

use App\Enums\CertificateTextPosition;
use App\Exceptions\ApiException;
use App\Models\CertificateTemplate;
use App\Models\DonorRecognition;
use App\Models\TierConfiguration;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

final class CertificatePdfService
{
    public function streamCertificate(DonorRecognition $recognition): Response
    {
        $filename = 'donor-certificate-'.$recognition->recognition_number.'.pdf';

        return new Response($this->renderCertificateBinary($recognition), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function streamCertificateInline(DonorRecognition $recognition): Response
    {
        $filename = 'donor-certificate-'.$recognition->recognition_number.'.pdf';

        return new Response($this->renderCertificateBinary($recognition), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function streamTemplatePreview(CertificateTemplate $template, string $awardeeName = 'Sample Donor'): Response
    {
        $template->loadMissing('tier');
        $tier = $template->tier;

        if ($tier === null) {
            throw new ApiException('Certificate template is not linked to a tier.', 422);
        }

        $recognition = $this->buildPreviewRecognition($template, $tier, $awardeeName);
        $filename = 'certificate-preview-'.preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($template->name)).'.pdf';

        return new Response($this->renderCertificateBinary($recognition), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function renderCertificateBinary(DonorRecognition $recognition): string
    {
        $recognition->loadMissing('tier', 'certificateTemplate');

        return $this->renderPdf(
            'pdf.donor-certificate',
            $this->certificateViewData($recognition),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function certificateViewData(DonorRecognition $recognition): array
    {
        $recognition->loadMissing('tier', 'certificateTemplate');

        $snapshot = is_array($recognition->snapshot) ? $recognition->snapshot : [];
        $design = is_array($snapshot['design'] ?? null)
            ? $snapshot['design']
            : (is_array($recognition->certificateTemplate?->design) ? $recognition->certificateTemplate->design : []);

        $replacements = [
            '{{awardee_name}}' => $recognition->awardee_name,
            '{{donor_name}}' => $recognition->awardee_name,
            '{{tier_name}}' => (string) ($recognition->tier?->name ?? $snapshot['tier_name'] ?? ''),
            '{{recognition_number}}' => $recognition->recognition_number,
            '{{issued_date}}' => $recognition->issued_at?->format('F j, Y') ?? now()->format('F j, Y'),
        ];

        $lines = [];
        foreach ((array) ($design['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $text = (string) ($line['text'] ?? '');
            foreach ($replacements as $search => $replace) {
                $text = str_replace($search, $replace, $text);
            }

            $lines[] = [
                'text' => $text,
                'font' => (string) ($line['font'] ?? 'DejaVu Sans'),
                'size' => (string) ($line['size'] ?? '14px'),
                'weight' => (string) ($line['weight'] ?? 'normal'),
                'position' => $this->resolveTextAlign((string) ($line['position'] ?? 'center')),
            ];
        }

        $signatories = [];
        foreach ((array) ($design['signatories'] ?? []) as $signatory) {
            if (! is_array($signatory)) {
                continue;
            }

            $signatories[] = [
                'name' => (string) ($signatory['name'] ?? ''),
                'position' => (string) ($signatory['position'] ?? ''),
                'signature_data_uri' => $this->resolveRemoteImageDataUri($signatory['signature_url'] ?? null),
            ];
        }

        return [
            'recognitionNumber' => $recognition->recognition_number,
            'awardeeName' => $recognition->awardee_name,
            'tierName' => (string) ($recognition->tier?->name ?? $snapshot['tier_name'] ?? ''),
            'issuedAt' => $recognition->issued_at?->format('F j, Y') ?? now()->format('F j, Y'),
            'backgroundDataUri' => $this->resolveRemoteImageDataUri($design['image_url'] ?? null),
            'iconDataUri' => $this->resolveRemoteImageDataUri($design['icon_url'] ?? null),
            'sealDataUri' => $this->resolveRemoteImageDataUri($design['seal_image_url'] ?? null),
            'awardeeFont' => (string) ($design['awardee_font'] ?? 'DejaVu Sans'),
            'awardeeFontSize' => (string) ($design['awardee_font_size'] ?? '28px'),
            'awardeeFontWeight' => (string) ($design['awardee_font_weight'] ?? 'bold'),
            'awardeeTextAlign' => $this->resolveTextAlign((string) ($design['general_text_position'] ?? 'center')),
            'iconPosition' => $this->resolveTextAlign((string) ($design['icon_position'] ?? 'center')),
            'lines' => $lines,
            'signatories' => $signatories,
        ];
    }

    /**
     * @param  array<string, mixed>  $viewData
     */
    private function renderPdf(string $view, array $viewData): string
    {
        $html = view($view, $viewData)->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildPreviewRecognition(
        CertificateTemplate $template,
        TierConfiguration $tier,
        string $awardeeName,
    ): DonorRecognition {
        $design = is_array($template->design) ? $template->design : [];
        $recognition = new DonorRecognition([
            'recognition_number' => 'ICOBA-REC-PREVIEW',
            'awardee_name' => $awardeeName,
            'issued_at' => now(),
            'snapshot' => [
                'tier_name' => $tier->name,
                'template_name' => $template->name,
                'design' => $design,
            ],
        ]);
        $recognition->setRelation('tier', $tier);
        $recognition->setRelation('certificateTemplate', $template);

        return $recognition;
    }

    private function resolveTextAlign(string $position): string
    {
        return match (CertificateTextPosition::tryFrom(strtolower(trim($position)))) {
            CertificateTextPosition::LEFT => 'left',
            CertificateTextPosition::RIGHT => 'right',
            default => 'center',
        };
    }

    private function resolveRemoteImageDataUri(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if (str_starts_with($url, 'data:')) {
            return $url;
        }

        try {
            $response = Http::timeout(8)->get($url);
            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            if ($body === '') {
                return null;
            }

            $mime = $response->header('Content-Type') ?: 'image/png';
            $mime = strtok($mime, ';') ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode($body);
        } catch (\Throwable) {
            return null;
        }
    }
}
