<?php

namespace App\Jobs;

use App\Enums\ModuleEnums;
use App\Mail\DonationTaxReceiptMail;
use App\Models\Transaction;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Receipt\ReceiptPdfService;
use App\Services\Receipt\ReceiptService;
use App\Services\Theme\ThemeResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDonationTaxReceiptEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $transactionUuid,
    ) {}

    public function uniqueId(): string
    {
        return $this->transactionUuid;
    }

    public function handle(
        ReceiptService $receiptService,
        ReceiptPdfService $receiptPdfService,
        ThemeResolver $themeResolver,
        NotificationDispatchService $notificationDispatch,
    ): void {
        $transaction = Transaction::query()
            ->where('uuid', $this->transactionUuid)
            ->with('donor')
            ->first();

        if ($transaction === null || ! $receiptService->isEligibleForTaxReceipt($transaction)) {
            return;
        }

        $recipientEmail = trim((string) ($transaction->donor_email ?? $transaction->donor?->email ?? ''));
        if ($recipientEmail === '') {
            return;
        }

        try {
            $corporate = $receiptService->corporateDetails($transaction);
            $recipientName = $corporate['organization_name'] ?? $receiptService->donorDisplayLine($transaction) ?? 'Donor';
            $theme = $themeResolver->resolveForMail();
            $pdfBinary = $receiptPdfService->renderTaxReceiptBinary($transaction);

            Mail::to($recipientEmail)->send(new DonationTaxReceiptMail(
                transaction: $transaction,
                mailTheme: $theme,
                recipientName: $recipientName,
                taxReceiptDownloadUrl: $receiptService->guestTaxReceiptDownloadUrl($transaction),
                donationReceiptDownloadUrl: $receiptService->guestDonationReceiptDownloadUrl($transaction),
                pdfBinary: $pdfBinary,
            ));

            $notificationDispatch->notifyDonor(
                $transaction->user_uuid,
                $transaction->donor_email ?? $transaction->donor?->email,
                new GenericDatabaseNotification(
                    module: ModuleEnums::transactions->value,
                    event: 'donation.tax_receipt_ready',
                    title: 'Tax receipt available',
                    message: 'Your tax-deductible donation receipt is ready to download.',
                    meta: [
                        'transaction_uuid' => $transaction->uuid,
                        'transaction_id' => $transaction->transaction_id,
                        'amount' => (string) $transaction->amount,
                        'currency' => strtoupper((string) $transaction->currency),
                        'paid_at' => optional($transaction->paid_at)->toIso8601String(),
                    ],
                    actionUrl: $receiptService->guestTaxReceiptDownloadUrl($transaction),
                    icon: '/icons/tax-receipt.png',
                    severity: 'info',
                    tags: ['donation', 'tax_receipt'],
                    sendMail: false,
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Donation tax receipt email failed: '.$e->getMessage(), [
                'transaction_uuid' => $this->transactionUuid,
                'to' => $recipientEmail,
            ]);
        }
    }
}
