<?php

namespace App\Jobs;

use App\Enums\ModuleEnums;
use App\Enums\TransactionStatus;
use App\Mail\DonationConfirmationMail;
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

class SendDonationConfirmationEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $transactionUuid,
    ) {}

    public function uniqueId(): string
    {
        return 'donation-confirmation:'.$this->transactionUuid;
    }

    public function handle(
        ReceiptService $receiptService,
        ReceiptPdfService $receiptPdfService,
        ThemeResolver $themeResolver,
        NotificationDispatchService $notificationDispatch,
    ): void {
        $transaction = Transaction::query()
            ->where('uuid', $this->transactionUuid)
            ->with(['donor', 'campaign'])
            ->first();

        if ($transaction === null || $transaction->status !== TransactionStatus::SUCCESSFUL) {
            return;
        }

        $recipientEmail = trim((string) ($transaction->donor_email ?? $transaction->donor?->email ?? ''));
        if ($recipientEmail === '') {
            return;
        }

        try {
            $transaction = $receiptService->ensurePublicReceiptAccess($transaction);
            $recipientName = $receiptService->donorDisplayLine($transaction) ?? 'Donor';
            $theme = $themeResolver->resolveForMail();
            $pdfBinary = $receiptPdfService->renderDonationReceiptBinary($transaction);
            $taxReceiptDownloadUrl = $receiptService->isEligibleForTaxReceipt($transaction)
                ? $receiptService->guestTaxReceiptDownloadUrl($transaction)
                : null;

            Mail::to($recipientEmail)->send(new DonationConfirmationMail(
                transaction: $transaction,
                mailTheme: $theme,
                recipientName: $recipientName,
                campaignName: $transaction->campaign?->name ?? 'General Endowment Fund',
                donationReceiptDownloadUrl: $receiptService->guestDonationReceiptDownloadUrl($transaction),
                taxReceiptDownloadUrl: $taxReceiptDownloadUrl,
                pdfBinary: $pdfBinary,
            ));

            $notificationDispatch->notifyDonor(
                $transaction->user_uuid,
                $transaction->donor_email ?? $transaction->donor?->email,
                new GenericDatabaseNotification(
                    module: ModuleEnums::transactions->value,
                    event: 'donation.confirmed',
                    title: 'Donation received',
                    message: 'Thank you! Your donation has been confirmed. A receipt has been emailed to you.',
                    meta: [
                        'transaction_uuid' => $transaction->uuid,
                        'transaction_id' => $transaction->transaction_id,
                        'amount' => (string) $transaction->amount,
                        'currency' => strtoupper((string) $transaction->currency),
                        'paid_at' => optional($transaction->paid_at)->toIso8601String(),
                        'gateway' => $transaction->gateway,
                        'campaign_name' => $transaction->campaign?->name,
                    ],
                    actionUrl: $receiptService->guestDonationReceiptDownloadUrl($transaction),
                    icon: '/icons/donation-confirmed.png',
                    severity: 'success',
                    tags: ['donation', 'confirmation'],
                    sendMail: false,
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Donation confirmation email failed: '.$e->getMessage(), [
                'transaction_uuid' => $this->transactionUuid,
                'to' => $recipientEmail,
            ]);
        }
    }
}
