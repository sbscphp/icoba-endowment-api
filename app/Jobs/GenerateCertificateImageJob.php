<?php

namespace App\Jobs;

use App\Enums\IssuedCertificateStatus;
use App\Models\DonorRecognition;
use App\Services\Recognition\CertificateImageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateCertificateImageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $recognitionUuid,
        public readonly bool $force = false,
    ) {}

    public function handle(CertificateImageService $certificateImageService): void
    {
        $recognition = DonorRecognition::query()
            ->where('uuid', $this->recognitionUuid)
            ->first();

        if ($recognition === null || $recognition->status === IssuedCertificateStatus::REVOKED) {
            return;
        }

        try {
            $certificateImageService->ensureCertificateImageUrl($recognition, $this->force);
        } catch (\Throwable $e) {
            Log::warning('GenerateCertificateImageJob failed: '.$e->getMessage(), [
                'recognition_uuid' => $this->recognitionUuid,
            ]);
        }
    }
}
