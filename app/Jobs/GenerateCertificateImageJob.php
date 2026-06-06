<?php

namespace App\Jobs;

use App\Enums\IssuedCertificateStatus;
use App\Enums\ModuleEnums;
use App\Models\DonorRecognition;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Recognition\CertificateImageService;
use App\Services\Recognition\DonorRecognitionService;
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

    public function handle(
        CertificateImageService $certificateImageService,
        NotificationDispatchService $notificationDispatch,
        DonorRecognitionService $recognitionService,
    ): void {
        $recognition = DonorRecognition::query()
            ->where('uuid', $this->recognitionUuid)
            ->first();

        if ($recognition === null || $recognition->status === IssuedCertificateStatus::REVOKED) {
            return;
        }

        $previousUrl = $recognition->certificate_image_url;

        try {
            $url = $certificateImageService->ensureCertificateImageUrl($recognition, $this->force);
        } catch (\Throwable $e) {
            Log::warning('GenerateCertificateImageJob failed: '.$e->getMessage(), [
                'recognition_uuid' => $this->recognitionUuid,
            ]);

            return;
        }

        if ($url === null) {
            return;
        }

        $fresh = $recognition->fresh() ?? $recognition;

        $shouldNotify = $this->force
            || blank($previousUrl)
            || $previousUrl !== $fresh->certificate_image_url;

        if (! $shouldNotify) {
            return;
        }

        $sendMail = $fresh->email_sent_at === null;

        $notificationDispatch->notifyDonor(
            $fresh->user_uuid,
            $fresh->donor_email,
            new GenericDatabaseNotification(
                module: ModuleEnums::issued_certificate->value,
                event: 'recognition.certificate_ready',
                title: 'Your certificate is ready',
                message: 'Your recognition certificate is ready to view and download.',
                meta: [
                    'recognition_uuid' => $fresh->uuid,
                    'recognition_number' => $fresh->recognition_number,
                    'certificate_image_url' => $fresh->certificate_image_url,
                    'tier_uuid' => $fresh->tier_uuid,
                ],
                actionUrl: $recognitionService->guestCertificateDownloadUrl($fresh),
                mailSubject: 'Your recognition certificate is ready',
                icon: '/icons/certificate-ready.png',
                severity: 'success',
                tags: ['recognition', 'certificate_ready'],
                sendMail: $sendMail,
            ),
        );
    }
}
