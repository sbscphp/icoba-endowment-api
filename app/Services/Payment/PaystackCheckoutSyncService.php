<?php

namespace App\Services\Payment;

use App\Enums\TransactionStatus;
use App\Jobs\EvaluateDonorTierRecognitionJob;
use App\Jobs\SendDonationConfirmationEmailJob;
use App\Jobs\SendDonationTaxReceiptEmailJob;
use App\Models\Transaction;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Receipt\ReceiptService;
use App\Services\Transaction\TransactionNgnSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PaystackCheckoutSyncService
{
    public function __construct(
        private readonly PledgeBalanceService $pledgeBalance,
        private readonly ReceiptService $receiptService,
        private readonly TransactionNgnSnapshotService $transactionNgnSnapshot,
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

        return (bool) DB::transaction(function () use ($transaction, $checkoutTransaction, $gatewayReference): bool {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== TransactionStatus::PENDING || $locked->gateway !== 'paystack') {
                return false;
            }

            if (! $this->transactionMatchesCheckout($locked, $checkoutTransaction)) {
                Log::warning('Paystack checkout sync: transaction does not match checkout reference.', [
                    'transaction_uuid' => $locked->uuid,
                    'reference' => $checkoutTransaction->reference,
                    'gateway_reference' => $locked->gateway_reference,
                ]);

                return false;
            }

            $meta = is_array($locked->metadata) ? $locked->metadata : [];
            $shouldQueueConfirmationEmail = ! array_key_exists('donation_confirmation_email_queued', $meta);
            $shouldQueueTaxReceiptEmail = ! array_key_exists('paystack_tax_receipt_email_queued', $meta);

            $meta = array_merge($meta, [
                'paystack_reference' => $checkoutTransaction->reference,
                'paystack_transaction_id' => $checkoutTransaction->paystackTransactionId,
                'paystack_status' => $checkoutTransaction->status,
                'payment_method' => 'paystack',
                'paystack_checkout_finalized_at' => $meta['paystack_checkout_finalized_at'] ?? now()->toIso8601String(),
            ]);

            if ($shouldQueueConfirmationEmail) {
                $meta['donation_confirmation_email_queued'] = true;
            }

            if ($shouldQueueTaxReceiptEmail) {
                $meta['paystack_tax_receipt_email_queued'] = true;
            }

            $ngnFields = [];
            if ($locked->amount_in_naira === null || $locked->exchange_rate_to_naira === null) {
                $snapshot = $this->transactionNgnSnapshot->resolve(
                    (float) $locked->amount,
                    (string) $locked->currency,
                );

                if ($locked->amount_in_naira === null) {
                    $ngnFields['amount_in_naira'] = $snapshot['amount_in_naira'];
                }

                if ($locked->exchange_rate_to_naira === null) {
                    $ngnFields['exchange_rate_to_naira'] = $snapshot['exchange_rate_to_naira'];
                }
            }

            $locked->forceFill(array_merge([
                'status' => TransactionStatus::SUCCESSFUL,
                'paid_at' => $locked->paid_at ?? now(),
                'gateway_reference' => $gatewayReference,
                'metadata' => $meta,
            ], $ngnFields))->save();

            $locked->loadMissing('pledge');
            if ($locked->pledge !== null) {
                $this->pledgeBalance->refreshPledgeStatus($locked->pledge);
            }

            $this->receiptService->ensurePublicReceiptAccess($locked);

            if ($shouldQueueConfirmationEmail) {
                SendDonationConfirmationEmailJob::dispatch($locked->uuid);
            }

            if ($shouldQueueTaxReceiptEmail) {
                SendDonationTaxReceiptEmailJob::dispatch($locked->uuid);
            }

            EvaluateDonorTierRecognitionJob::dispatch($locked->uuid);

            return true;
        });
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
