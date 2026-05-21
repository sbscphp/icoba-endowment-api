<?php

namespace App\Services\Payment;

use App\Enums\TransactionStatus;
use App\Jobs\SendDonationConfirmationEmailJob;
use App\Jobs\SendDonationTaxReceiptEmailJob;
use App\Models\Transaction;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Receipt\ReceiptService;
use App\Services\Transaction\TransactionNgnSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;

final class StripeCheckoutSyncService
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
    public function syncFromSession(Session $session): ?Transaction
    {
        $transaction = $this->resolveTransaction($session);
        if ($transaction === null) {
            return null;
        }

        if ($session->payment_status === 'paid') {
            $this->finalizeIfPaid($session);
        } elseif ($session->status === 'expired') {
            $this->markPendingFailed($session);
        }

        return Transaction::query()->whereKey($transaction->getKey())->first();
    }

    /**
     * Finalize a paid Checkout Session. Safe to call from webhook or manual verify — only
     * the first caller to hold the row lock while status is pending will apply side effects.
     */
    public function finalizeIfPaid(Session $session): bool
    {
        if ($session->payment_status !== 'paid') {
            return false;
        }

        $transaction = $this->resolveTransaction($session);
        if ($transaction === null || $transaction->gateway !== 'stripe') {
            return false;
        }

        if ($transaction->status !== TransactionStatus::PENDING) {
            return false;
        }

        $gatewayReference = is_string($session->payment_intent) && $session->payment_intent !== ''
            ? $session->payment_intent
            : $session->id;

        return (bool) DB::transaction(function () use ($transaction, $session, $gatewayReference): bool {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== TransactionStatus::PENDING || $locked->gateway !== 'stripe') {
                return false;
            }

            if (! $this->sessionMatchesTransaction($locked, $session)) {
                Log::warning('Stripe checkout sync: session does not match transaction.', [
                    'transaction_uuid' => $locked->uuid,
                    'session_id' => $session->id,
                    'gateway_reference' => $locked->gateway_reference,
                ]);

                return false;
            }

            $meta = is_array($locked->metadata) ? $locked->metadata : [];
            $shouldQueueConfirmationEmail = ! array_key_exists('donation_confirmation_email_queued', $meta);
            $shouldQueueTaxReceiptEmail = ! array_key_exists('stripe_tax_receipt_email_queued', $meta);

            $meta = array_merge($meta, [
                'stripe_checkout_session_id' => $session->id,
                'stripe_payment_intent_id' => $session->payment_intent,
                'stripe_payment_status' => $session->payment_status,
                'payment_method' => 'stripe',
                'stripe_checkout_finalized_at' => $meta['stripe_checkout_finalized_at'] ?? now()->toIso8601String(),
            ]);

            if ($shouldQueueConfirmationEmail) {
                $meta['donation_confirmation_email_queued'] = true;
            }

            if ($shouldQueueTaxReceiptEmail) {
                $meta['stripe_tax_receipt_email_queued'] = true;
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

            return true;
        });
    }

    /**
     * Mark an expired Checkout Session as failed. Idempotent — pending transactions only.
     */
    public function markPendingFailed(Session $session): bool
    {
        $transaction = $this->resolveTransaction($session);
        if ($transaction === null || $transaction->gateway !== 'stripe') {
            return false;
        }

        if ($transaction->status !== TransactionStatus::PENDING) {
            return false;
        }

        return (bool) DB::transaction(function () use ($transaction, $session): bool {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== TransactionStatus::PENDING || $locked->gateway !== 'stripe') {
                return false;
            }

            if (! $this->sessionMatchesTransaction($locked, $session)) {
                return false;
            }

            $locked->forceFill([
                'status' => TransactionStatus::FAILED,
            ])->save();

            return true;
        });
    }

    public function resolveTransaction(Session $session): ?Transaction
    {
        $uuid = $session->metadata['transaction_uuid'] ?? null;
        if (! is_string($uuid) || $uuid === '') {
            Log::warning('Stripe checkout sync: session missing transaction_uuid metadata.', [
                'session_id' => $session->id,
            ]);

            return null;
        }

        return Transaction::query()->where('uuid', $uuid)->first();
    }

    private function sessionMatchesTransaction(Transaction $transaction, Session $session): bool
    {
        $reference = $transaction->gateway_reference;
        if (! is_string($reference) || $reference === '') {
            return true;
        }

        $sessionId = $session->id;
        $paymentIntentId = is_string($session->payment_intent) && $session->payment_intent !== ''
            ? $session->payment_intent
            : null;

        return $reference === $sessionId || ($paymentIntentId !== null && $reference === $paymentIntentId);
    }
}
