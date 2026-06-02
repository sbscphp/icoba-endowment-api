<?php

namespace App\Jobs;

use App\Enums\IssuedCertificateStatus;
use App\Enums\ModuleEnums;
use App\Enums\TransactionStatus;
use App\Mail\DonorRecognitionMail;
use App\Models\DonorRecognition;
use App\Models\Transaction;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Receipt\ReceiptPdfService;
use App\Services\Receipt\ReceiptService;
use App\Services\Recognition\CertificatePdfService;
use App\Services\Recognition\DonorRecognitionService;
use App\Services\Theme\ThemeResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDonorRecognitionEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $recognitionUuid,
        public readonly string $transactionUuid,
    ) {}

    public function uniqueId(): string
    {
        return 'donor-recognition-email:'.$this->recognitionUuid;
    }

    // What blocks issuance
    // Condition	                Result
    // Anonymous donation => Skipped
    // No awardee name => Skipped
    // Cumulative total below any tier threshold => Skipped
    // Tier already issued for that donor => Skipped
    // No active certificate template for tier => Skipped
    // No donor email => Record created, but email job exits early

    public function handle(
        DonorRecognitionService $recognitionService,
        CertificatePdfService $certificatePdfService,
        ReceiptService $receiptService,
        ReceiptPdfService $receiptPdfService,
        ThemeResolver $themeResolver,
        NotificationDispatchService $notificationDispatch,
    ): void {
        $recognition = DonorRecognition::query()
            ->with(['tier', 'certificateTemplate'])
            ->where('uuid', $this->recognitionUuid)
            ->first();

        if ($recognition === null || $recognition->email_sent_at !== null) {
            return;
        }

        $transaction = Transaction::query()
            ->where('uuid', $this->transactionUuid)
            ->with(['donor', 'campaign'])
            ->first();

        if ($transaction === null || $transaction->status !== TransactionStatus::SUCCESSFUL) {
            return;
        }

        $recipientEmail = trim((string) ($recognition->donor_email ?? $transaction->donor_email ?? $transaction->donor?->email ?? ''));
        if ($recipientEmail === '') {
            return;
        }

        try {
            $transaction = $receiptService->ensurePublicReceiptAccess($transaction);
            $theme = $themeResolver->resolveForMail();
            $certificatePdf = $certificatePdfService->renderCertificateBinary($recognition);
            $receiptPdf = $receiptPdfService->renderDonationReceiptBinary($transaction);

            $certificateDownloadUrl = $recognitionService->guestCertificateDownloadUrl($recognition);

            Mail::to($recipientEmail)->send(new DonorRecognitionMail(
                recognition: $recognition,
                transaction: $transaction,
                mailTheme: $theme,
                recipientName: $recognition->awardee_name,
                tierName: (string) ($recognition->tier?->name ?? ''),
                certificateDownloadUrl: $certificateDownloadUrl,
                donationReceiptDownloadUrl: $receiptService->guestDonationReceiptDownloadUrl($transaction),
                certificatePdfBinary: $certificatePdf,
                receiptPdfBinary: $receiptPdf,
            ));

            $recognition->forceFill(['email_sent_at' => now()])->save();

            $isReissue = $recognition->status === IssuedCertificateStatus::REISSUED;
            $notificationDispatch->notifyDonor(
                $recognition->user_uuid,
                $recognition->donor_email ?? $transaction->donor_email ?? $transaction->donor?->email,
                new GenericDatabaseNotification(
                    module: ModuleEnums::issued_certificate->value,
                    event: $isReissue ? 'recognition.reissued' : 'recognition.issued',
                    title: $isReissue ? 'Your certificate has been reissued' : 'You have earned a new recognition',
                    message: $isReissue
                        ? 'A new copy of your recognition certificate has been issued and emailed to you.'
                        : 'Congratulations! Your recognition certificate has been issued and emailed to you.',
                    meta: [
                        'recognition_uuid' => $recognition->uuid,
                        'recognition_number' => $recognition->recognition_number,
                        'tier_name' => (string) ($recognition->tier?->name ?? ''),
                        'cumulative_amount_ngn' => (string) $recognition->cumulative_amount_ngn,
                        'transaction_uuid' => $transaction->uuid,
                    ],
                    actionUrl: $certificateDownloadUrl,
                    icon: '/icons/recognition.png',
                    severity: 'success',
                    tags: ['recognition', $isReissue ? 'reissued' : 'issued'],
                    sendMail: false,
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Donor recognition email failed: '.$e->getMessage(), [
                'recognition_uuid' => $this->recognitionUuid,
                'transaction_uuid' => $this->transactionUuid,
                'to' => $recipientEmail,
            ]);
        }
    }
}
