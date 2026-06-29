<?php

namespace App\Services\Payment;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Transaction\TransactionFinalizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class FcmbCheckoutSyncService
{
    public function __construct(
        private readonly TransactionFinalizationService $finalizationService,
    ) {}

    /**
     * Secondary confirmation path: applies the same state transitions as the webhook when safe.
     */
    public function syncFromTransaction(FcmbCheckoutTransaction $checkoutTransaction): ?Transaction
    {
        $transaction = $this->resolveTransaction($checkoutTransaction);
        if ($transaction === null) {
            return null;
        }

        if ($checkoutTransaction->isPaid()) {
            $this->finalizeIfPaid($checkoutTransaction);
        } elseif ($checkoutTransaction->isFailed()) {
            $this->markPendingFailed($checkoutTransaction);
        }

        return Transaction::query()->whereKey($transaction->getKey())->first();
    }

    public function finalizeIfPaid(FcmbCheckoutTransaction $checkoutTransaction): bool
    {
        if (! $checkoutTransaction->isPaid()) {
            return false;
        }

        $transaction = $this->resolveTransaction($checkoutTransaction);
        if ($transaction === null || $transaction->gateway !== 'fcmb') {
            return false;
        }

        if ($transaction->status !== TransactionStatus::PENDING) {
            return false;
        }

        if (! $this->transactionMatchesCheckout($transaction, $checkoutTransaction)) {
            Log::warning('FCMB checkout sync: transaction does not match checkout reference.', [
                'transaction_uuid' => $transaction->uuid,
                'invoice_request_reference' => $checkoutTransaction->invoiceRequestReference,
                'gateway_reference' => $transaction->gateway_reference,
            ]);

            return false;
        }

        $gatewayReference = $checkoutTransaction->reference ?? $checkoutTransaction->invoiceRequestReference;

        return $this->finalizationService->finalizeSuccessful($transaction, [
            'gateway_reference' => $gatewayReference,
            'metadata' => [
                'payment_method' => 'fcmb_checkout',
                'fcmb_invoice_request_reference' => $checkoutTransaction->invoiceRequestReference,
                'fcmb_reference' => $checkoutTransaction->reference,
                'fcmb_status' => $checkoutTransaction->status,
                'fcmb_checkout_finalized_at' => now()->toIso8601String(),
            ],
            'tax_receipt_email_meta_key' => 'fcmb_tax_receipt_email_queued',
        ]);
    }

    public function markPendingFailed(FcmbCheckoutTransaction $checkoutTransaction): bool
    {
        $transaction = $this->resolveTransaction($checkoutTransaction);
        if ($transaction === null || $transaction->gateway !== 'fcmb') {
            return false;
        }

        if ($transaction->status !== TransactionStatus::PENDING) {
            return false;
        }

        return (bool) DB::transaction(function () use ($transaction, $checkoutTransaction): bool {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== TransactionStatus::PENDING || $locked->gateway !== 'fcmb') {
                return false;
            }

            if (! $this->transactionMatchesCheckout($locked, $checkoutTransaction)) {
                return false;
            }

            $locked->forceFill([
                'status' => TransactionStatus::FAILED,
            ])->save();

            return true;
        });
    }

    public function resolveTransaction(FcmbCheckoutTransaction $checkoutTransaction): ?Transaction
    {
        $uuid = $checkoutTransaction->transactionUuid;
        if (is_string($uuid) && $uuid !== '') {
            $transaction = Transaction::query()->where('uuid', $uuid)->first();
            if ($transaction !== null) {
                return $transaction;
            }
        }

        $reference = $checkoutTransaction->invoiceRequestReference;
        if ($reference === '') {
            Log::warning('FCMB checkout sync: missing invoice request reference.');

            return null;
        }

        return Transaction::query()
            ->where('gateway_reference', $reference)
            ->orWhere('uuid', $reference)
            ->first();
    }

    private function transactionMatchesCheckout(Transaction $transaction, FcmbCheckoutTransaction $checkoutTransaction): bool
    {
        $reference = $transaction->gateway_reference;
        if (! is_string($reference) || $reference === '') {
            return true;
        }

        return $reference === $checkoutTransaction->invoiceRequestReference
            || $reference === $transaction->uuid;
    }
}
