<?php

namespace App\Services\Payment;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Receipt\ReceiptService;

final class CheckoutVerificationReceiptResolver
{
    public function __construct(
        private readonly ReceiptService $receiptService,
    ) {}

    public function resolve(string $paymentStatus, Transaction $transaction): ?string
    {
        if (! in_array($paymentStatus, ['paid', 'complete', 'no_payment_required'], true)) {
            return null;
        }

        if ($transaction->status !== TransactionStatus::SUCCESSFUL) {
            return null;
        }

        return $this->receiptService->ensurePublicReceiptAccess($transaction)->receipt_number;
    }
}
