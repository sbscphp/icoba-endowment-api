<?php

namespace App\Jobs;

use App\Enums\TransactionStatus;
use App\Mail\DonorRecognitionMail;
use App\Models\DonorRecognition;
use App\Models\Transaction;
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

            Mail::to($recipientEmail)->send(new DonorRecognitionMail(
                recognition: $recognition,
                transaction: $transaction,
                mailTheme: $theme,
                recipientName: $recognition->awardee_name,
                tierName: (string) ($recognition->tier?->name ?? ''),
                certificateDownloadUrl: $recognitionService->guestCertificateDownloadUrl($recognition),
                donationReceiptDownloadUrl: $receiptService->guestDonationReceiptDownloadUrl($transaction),
                certificatePdfBinary: $certificatePdf,
                receiptPdfBinary: $receiptPdf,
            ));

            $recognition->forceFill(['email_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('Donor recognition email failed: '.$e->getMessage(), [
                'recognition_uuid' => $this->recognitionUuid,
                'transaction_uuid' => $this->transactionUuid,
                'to' => $recipientEmail,
            ]);
        }
    }
}
