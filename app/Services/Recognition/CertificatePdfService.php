<?php

namespace App\Services\Recognition;

use App\Enums\CertificateImageType;
use App\Enums\CertificatePreviewFormat;
use App\Enums\CertificateTextPosition;
use App\Exceptions\ApiException;
use App\Models\CertificateTemplate;
use App\Models\DonorRecognition;
use App\Models\TierConfiguration;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

final class CertificatePdfService
{
    public function __construct(
        private readonly CertificateAssetResolver $assetResolver,
    ) {}
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
        return $this->streamTemplatePreviewByFormat($template, $awardeeName, CertificatePreviewFormat::Pdf);
    }

    public function renderCertificateBinary(DonorRecognition $recognition): string
    {
        $recognition->loadMissing('tier', 'certificateTemplate');

        return $this->renderPdf(
            'pdf.donor-certificate',
            $this->certificateViewData($recognition),
        );
    }

    public function renderCertificateHtml(DonorRecognition $recognition): string
    {
        $recognition->loadMissing('tier', 'certificateTemplate');

        return view('pdf.donor-certificate', $this->certificateViewData($recognition))->render();
    }

    public function streamCertificateByFormat(DonorRecognition $recognition, CertificatePreviewFormat $format): Response
    {
        return match ($format) {
            CertificatePreviewFormat::Html => new Response(
                $this->renderCertificateHtml($recognition),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            CertificatePreviewFormat::Png => new Response(
                app(CertificateImageService::class)->renderPngBinary($recognition),
                200,
                ['Content-Type' => 'image/png'],
            ),
            CertificatePreviewFormat::Pdf => $this->streamCertificateInline($recognition),
        };
    }

    public function streamTemplatePreviewByFormat(
        CertificateTemplate $template,
        string $awardeeName,
        CertificatePreviewFormat $format,
    ): Response {
        $template->loadMissing('tier');
        $tier = $template->tier;

        if ($tier === null) {
            throw new ApiException('Certificate template is not linked to a tier.', 422);
        }

        $recognition = $this->buildPreviewRecognition($template, $tier, $awardeeName);

        return $this->streamCertificateByFormat($recognition, $format);
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
                'weight' => $this->normalizeFontWeight((string) ($line['weight'] ?? 'normal')),
                'position' => $this->resolveTextAlign((string) ($line['position'] ?? 'center')),
            ];
        }

        [$linesBeforeName, $linesAfterName] = $this->splitLinesAroundAwardeeName($lines, $design);

        $signatories = [];
        foreach ((array) ($design['signatories'] ?? []) as $signatory) {
            if (! is_array($signatory)) {
                continue;
            }

            $signature = $this->assetResolver->resolve($signatory['signature_url'] ?? null);

            $signatories[] = [
                'name' => (string) ($signatory['name'] ?? ''),
                'position' => (string) ($signatory['position'] ?? ''),
                'signature_data_uri' => $signature['data_uri'],
                'signature_url' => $signature['url'],
            ];
        }

        $background = $this->assetResolver->resolve($design['image_url'] ?? null);
        $icon = $this->assetResolver->resolve($design['icon_url'] ?? null);
        $seal = $this->assetResolver->resolve($design['seal_image_url'] ?? null);
        $imageType = CertificateImageType::tryFrom((string) ($design['image_type'] ?? ''))
            ?? CertificateImageType::BACKGROUND;

        return [
            'recognitionNumber' => $recognition->recognition_number,
            'awardeeName' => $recognition->awardee_name,
            'tierName' => (string) ($recognition->tier?->name ?? $snapshot['tier_name'] ?? ''),
            'issuedAt' => $recognition->issued_at?->format('F j, Y') ?? now()->format('F j, Y'),
            'imageType' => $imageType->value,
            'sideImageDataUri' => $background['data_uri'],
            'sideImageUrl' => $background['url'],
            'backgroundDataUri' => $imageType === CertificateImageType::BACKGROUND ? $background['data_uri'] : null,
            'backgroundUrl' => $imageType === CertificateImageType::BACKGROUND ? $background['url'] : null,
            'iconDataUri' => $icon['data_uri'],
            'iconUrl' => $icon['url'],
            'sealDataUri' => $seal['data_uri'],
            'sealUrl' => $seal['url'],
            'awardeeFont' => (string) ($design['awardee_font'] ?? 'DejaVu Sans'),
            'awardeeFontSize' => (string) ($design['awardee_font_size'] ?? '28px'),
            'awardeeFontWeight' => $this->normalizeFontWeight((string) ($design['awardee_font_weight'] ?? 'bold')),
            'awardeeTextAlign' => $this->resolveTextAlign((string) ($design['general_text_position'] ?? 'center')),
            'iconPosition' => $this->resolveTextAlign((string) ($design['icon_position'] ?? 'center')),
            'linesBeforeName' => $linesBeforeName,
            'linesAfterName' => $linesAfterName,
            'lines' => $lines,
            'signatories' => $signatories,
            'leftSignatory' => $signatories[0] ?? null,
            'rightSignatory' => $signatories[1] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $viewData
     */
    private function renderPdf(string $view, array $viewData): string
    {
        $html = view($view, $viewData)->render();

        $options = new Options;
        $options->set('isRemoteEnabled', true);
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

    /**
     * @param  list<array{text: string, font: string, size: string, weight: string, position: string}>  $lines
     * @param  array<string, mixed>  $design
     * @return array{0: list<array{text: string, font: string, size: string, weight: string, position: string}>, 1: list<array{text: string, font: string, size: string, weight: string, position: string}>}
     */
    private function splitLinesAroundAwardeeName(array $lines, array $design): array
    {
        if ($lines === []) {
            return [[], []];
        }

        $afterIndex = $design['awardee_name_after_line'] ?? null;
        if ($afterIndex === null) {
            foreach ($lines as $index => $line) {
                if (stripos($line['text'], 'presented to') !== false) {
                    $afterIndex = $index;
                    break;
                }
            }
        }

        if ($afterIndex === null) {
            return [[], $lines];
        }

        $afterIndex = max(0, min((int) $afterIndex, count($lines) - 1));

        return [
            array_slice($lines, 0, $afterIndex + 1),
            array_slice($lines, $afterIndex + 1),
        ];
    }

    private function normalizeFontWeight(string $weight): string
    {
        return match (strtolower(trim($weight))) {
            'bold', '700', '800', '900' => 'bold',
            default => 'normal',
        };
    }
}
