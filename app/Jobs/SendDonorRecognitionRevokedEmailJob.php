<?php

namespace App\Jobs;

use App\Enums\IssuedCertificateStatus;
use App\Enums\ModuleEnums;
use App\Mail\DonorRecognitionRevokedMail;
use App\Models\DonorRecognition;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Theme\ThemeResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDonorRecognitionRevokedEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $recognitionUuid,
    ) {}

    public function uniqueId(): string
    {
        return 'donor-recognition-revoked-email:'.$this->recognitionUuid;
    }

    public function handle(
        ThemeResolver $themeResolver,
        NotificationDispatchService $notificationDispatch,
    ): void {
        $recognition = DonorRecognition::query()
            ->with(['tier', 'user'])
            ->where('uuid', $this->recognitionUuid)
            ->first();

        if ($recognition === null || $recognition->status !== IssuedCertificateStatus::REVOKED) {
            return;
        }

        $recipientEmail = trim((string) ($recognition->donor_email ?? $recognition->user?->email ?? ''));
        if ($recipientEmail === '') {
            return;
        }

        try {
            $theme = $themeResolver->resolveForMail();
            $recipientName = trim((string) ($recognition->awardee_name ?: $recognition->user?->displayName()));
            if ($recipientName === '') {
                $recipientName = 'Donor';
            }

            Mail::to($recipientEmail)->send(new DonorRecognitionRevokedMail(
                recognition: $recognition,
                mailTheme: $theme,
                recipientName: $recipientName,
                tierName: (string) ($recognition->tier?->name ?? ''),
            ));

            $notificationDispatch->notifyDonor(
                $recognition->user_uuid,
                $recipientEmail,
                new GenericDatabaseNotification(
                    module: ModuleEnums::issued_certificate->value,
                    event: 'recognition.revoked',
                    title: 'Your certificate has been revoked',
                    message: 'Your recognition certificate has been revoked and is no longer available for download.',
                    meta: [
                        'recognition_uuid' => $recognition->uuid,
                        'recognition_number' => $recognition->recognition_number,
                        'tier_name' => (string) ($recognition->tier?->name ?? ''),
                    ],
                    icon: '/icons/recognition.png',
                    severity: 'warning',
                    tags: ['recognition', 'revoked'],
                    sendMail: false,
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Donor recognition revocation email failed: '.$e->getMessage(), [
                'recognition_uuid' => $this->recognitionUuid,
                'to' => $recipientEmail,
            ]);
        }
    }
}
