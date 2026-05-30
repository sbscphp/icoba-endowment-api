<?php

namespace App\Console\Commands;

use App\Enums\IssuedCertificateStatus;
use App\Jobs\GenerateCertificateImageJob;
use App\Models\DonorRecognition;
use App\Services\Recognition\CertificateImageService;
use Illuminate\Console\Command;

class GenerateCertificateImagesCommand extends Command
{
    protected $signature = 'recognitions:generate-certificate-images
                            {--recognition= : Limit to a recognition UUID}
                            {--force : Regenerate even when certificate_image_url is set}
                            {--sync : Process inline instead of queueing jobs}';

    protected $description = 'Upload donor certificate JPEG previews to Cloudinary and persist certificate_image_url.';

    public function handle(CertificateImageService $certificateImageService): int
    {
        $recognitionUuid = trim((string) $this->option('recognition'));
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');

        $query = DonorRecognition::query()
            ->where('status', '!=', IssuedCertificateStatus::REVOKED);

        if ($recognitionUuid !== '') {
            $query->where('uuid', $recognitionUuid);
        } elseif (! $force) {
            $query->whereNull('certificate_image_url');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No recognitions require certificate image generation.');

            return self::SUCCESS;
        }

        $this->info("Processing {$total} recognition(s)...");

        $processed = 0;
        $failed = 0;

        $query->orderBy('id')->chunkById(50, function ($recognitions) use (
            $certificateImageService,
            $force,
            $sync,
            &$processed,
            &$failed,
        ): void {
            foreach ($recognitions as $recognition) {
                try {
                    if ($sync) {
                        $certificateImageService->ensureCertificateImageUrl($recognition, $force);
                    } else {
                        GenerateCertificateImageJob::dispatch($recognition->uuid, $force);
                    }

                    $processed++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("Failed {$recognition->uuid}: {$e->getMessage()}");
                }
            }
        });

        $mode = $sync ? 'generated' : 'queued';
        $this->info("{$processed} recognition(s) {$mode}. Failures: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
