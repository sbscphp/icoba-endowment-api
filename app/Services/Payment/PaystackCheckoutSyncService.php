<?php

namespace App\Services\Payment;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Transaction\TransactionFinalizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PaystackCheckoutSyncService
{
    public function __construct(
        private readonly TransactionFinalizationService $finalizationService,
    ) {}

    /**
     * Secondary confirmation path: applies the same state transitions as the webhook when safe.
     * Idempotent — no-op if the transaction is no longer pending.
     */
    public function syncFromTransaction(PaystackCheckoutTransaction $checkoutTransaction): ?Transaction
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

    /**
     * Finalize a successful Paystack transaction. Safe to call from webhook or manual verify.
     */
    public function finalizeIfPaid(PaystackCheckoutTransaction $checkoutTransaction): bool
    {
        if (! $checkoutTransaction->isPaid()) {
            return false;
        }

        $transaction = $this->resolveTransaction($checkoutTransaction);
        if ($transaction === null || $transaction->gateway !== 'paystack') {
            return false;
        }

        if ($transaction->status !== TransactionStatus::PENDING) {
            return false;
        }

        $gatewayReference = $checkoutTransaction->paystackTransactionId !== null
            ? (string) $checkoutTransaction->paystackTransactionId
            : $checkoutTransaction->reference;

        if (! $this->transactionMatchesCheckout($transaction, $checkoutTransaction)) {
            Log::warning('Paystack checkout sync: transaction does not match checkout reference.', [
                'transaction_uuid' => $transaction->uuid,
                'reference' => $checkoutTransaction->reference,
                'gateway_reference' => $transaction->gateway_reference,
            ]);

            return false;
        }

        return $this->finalizationService->finalizeSuccessful($transaction, [
            'gateway_reference' => $gatewayReference,
            'metadata' => [
                'paystack_reference' => $checkoutTransaction->reference,
                'paystack_transaction_id' => $checkoutTransaction->paystackTransactionId,
                'paystack_status' => $checkoutTransaction->status,
                'payment_method' => 'paystack',
                'paystack_checkout_finalized_at' => now()->toIso8601String(),
            ],
            'tax_receipt_email_meta_key' => 'paystack_tax_receipt_email_queued',
        ]);
    }

    /**
     * Mark a failed or abandoned Paystack transaction as failed. Idempotent — pending only.
     */
    public function markPendingFailed(PaystackCheckoutTransaction $checkoutTransaction): bool
    {
        $transaction = $this->resolveTransaction($checkoutTransaction);
        if ($transaction === null || $transaction->gateway !== 'paystack') {
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

            if ($locked === null || $locked->status !== TransactionStatus::PENDING || $locked->gateway !== 'paystack') {
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

    public function resolveTransaction(PaystackCheckoutTransaction $checkoutTransaction): ?Transaction
    {
        $uuid = $checkoutTransaction->transactionUuid;
        if (! is_string($uuid) || $uuid === '') {
            Log::warning('Paystack checkout sync: transaction missing transaction_uuid metadata.', [
                'reference' => $checkoutTransaction->reference,
            ]);

            return null;
        }

        return Transaction::query()->where('uuid', $uuid)->first();
    }

    private function transactionMatchesCheckout(Transaction $transaction, PaystackCheckoutTransaction $checkoutTransaction): bool
    {
        $reference = $transaction->gateway_reference;
        if (! is_string($reference) || $reference === '') {
            return true;
        }

        return $reference === $checkoutTransaction->reference
            || ($checkoutTransaction->paystackTransactionId !== null
                && $reference === (string) $checkoutTransaction->paystackTransactionId);
    }
}
