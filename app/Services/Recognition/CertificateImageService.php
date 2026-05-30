<?php

namespace App\Services\Recognition;

use App\Enums\IssuedCertificateStatus;
use App\Exceptions\ApiException;
use App\Models\DonorRecognition;
use Cloudinary\Cloudinary;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class CertificateImageService
{
    public function __construct(
        private readonly CertificatePdfService $certificatePdfService,
        private readonly Cloudinary $cloudinary,
    ) {}

    public function ensureCertificateImageUrl(DonorRecognition $recognition, bool $force = false): ?string
    {
        if (! $force && filled($recognition->certificate_image_url)) {
            return $recognition->certificate_image_url;
        }

        if ($recognition->status === IssuedCertificateStatus::REVOKED) {
            return null;
        }

        try {
            $url = $this->uploadCertificateJpeg($recognition);
            $recognition->forceFill(['certificate_image_url' => $url])->save();

            return $url;
        } catch (\Throwable $e) {
            Log::warning('Certificate image upload failed.', [
                'recognition_uuid' => $recognition->uuid,
                'recognition_number' => $recognition->recognition_number,
                'error' => $e->getMessage(),
            ]);

            return $recognition->certificate_image_url;
        }
    }

    public function uploadCertificateJpeg(DonorRecognition $recognition): string
    {
        $pdf = $this->certificatePdfService->renderCertificateBinary($recognition);
        $publicId = $this->certificatePublicId($recognition);
        $uploadedPublicId = $this->uploadPdfToCloudinary($pdf, $publicId);

        return $this->deliveryUrl($uploadedPublicId, 'jpg');
    }

    public function renderPngBinary(DonorRecognition $recognition): string
    {
        $pdf = $this->certificatePdfService->renderCertificateBinary($recognition);
        $previewId = $this->previewPublicId($recognition);
        $uploadedPublicId = $this->uploadPdfToCloudinary($pdf, $previewId);
        $pngUrl = $this->deliveryUrl($uploadedPublicId, 'png');

        $response = Http::timeout(30)->get($pngUrl);
        if (! $response->successful() || $response->body() === '') {
            throw new ApiException('Failed to render certificate PNG preview.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $response->body();
    }

    private function uploadPdfToCloudinary(string $pdfBinary, string $publicId): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'donor_cert_');
        if ($tempPath === false) {
            throw new ApiException('Unable to create a temporary certificate file.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            if (file_put_contents($tempPath, $pdfBinary) === false) {
                throw new ApiException('Unable to write temporary certificate PDF.', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $result = $this->cloudinary->uploadApi()->upload($tempPath, [
                'public_id' => $publicId,
                'resource_type' => 'image',
                'overwrite' => true,
                'unique_filename' => false,
            ]);

            return (string) ($result['public_id'] ?? $publicId);
        } finally {
            @unlink($tempPath);
        }
    }

    public function certificatePublicId(DonorRecognition $recognition): string
    {
        $folder = trim((string) config('recognitions.certificate_image_folder', 'certificates'), '/');

        return $folder.'/'.strtolower($recognition->recognition_number);
    }

    private function previewPublicId(DonorRecognition $recognition): string
    {
        $folder = trim((string) config('recognitions.certificate_image_preview_folder', 'certificates/previews'), '/');
        $suffix = filled($recognition->uuid) ? $recognition->uuid : (string) Str::uuid();

        return $folder.'/preview-'.$suffix;
    }

    private function deliveryUrl(string $publicId, string $format): string
    {
        $cloudName = $this->cloudinaryCloudName();
        $transformation = $format === 'png' ? 'pg_1,f_png,q_auto:good' : 'pg_1,f_jpg,q_auto:good';
        $normalizedPublicId = ltrim(str_replace('\\', '/', $publicId), '/');

        return sprintf(
            'https://res.cloudinary.com/%s/image/upload/%s/%s',
            $cloudName,
            $transformation,
            $normalizedPublicId,
        );
    }

    private function cloudinaryCloudName(): string
    {
        $configuration = $this->cloudinary->configuration;
        $cloud = $configuration->cloud ?? null;
        $cloudName = is_object($cloud) ? ($cloud->cloudName ?? null) : null;

        if (is_string($cloudName) && $cloudName !== '') {
            return $cloudName;
        }

        $fromConfig = config('filesystems.disks.cloudinary.cloud');
        if (is_string($fromConfig) && $fromConfig !== '') {
            return $fromConfig;
        }

        throw new ApiException('Cloudinary cloud name is not configured.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
