<?php

namespace App\Services\Payment;

use App\Enums\TransactionStatus;
use App\Jobs\SendDonationTaxReceiptEmailJob;
use App\Models\Transaction;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Receipt\ReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Event;

final class StripeWebhookService
{
    public function __construct(
        private readonly PledgeBalanceService $pledgeBalance,
        private readonly ReceiptService $receiptService,
    ) {}

    public function handleEvent(Event $event): void
    {
        match ($event->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->finalizeIfPaid($event->data->object),
            'checkout.session.async_payment_failed', 'checkout.session.expired' => $this->markPendingFailed($event->data->object),
            default => null,
        };
    }

    private function finalizeIfPaid(Session $session): void
    {
        if ($session->payment_status !== 'paid') {
            return;
        }

        $transaction = $this->resolveTransaction($session);
        if ($transaction === null || $transaction->gateway !== 'stripe') {
            return;
        }

        if ($transaction->status !== TransactionStatus::PENDING) {
            return;
        }

        $gatewayReference = is_string($session->payment_intent) && $session->payment_intent !== ''
            ? $session->payment_intent
            : $session->id;

        DB::transaction(function () use ($transaction, $session, $gatewayReference): void {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== TransactionStatus::PENDING || $locked->gateway !== 'stripe') {
                return;
            }

            if (
                is_string($locked->gateway_reference)
                && $locked->gateway_reference !== ''
                && $locked->gateway_reference !== $session->id
            ) {
                Log::warning('Stripe webhook: gateway_reference mismatch for session.', [
                    'transaction_uuid' => $locked->uuid,
                    'session_id' => $session->id,
                ]);

                return;
            }

            $meta = is_array($locked->metadata) ? $locked->metadata : [];
            $meta = array_merge($meta, [
                'stripe_checkout_session_id' => $session->id,
                'stripe_payment_intent_id' => $session->payment_intent,
                'stripe_payment_status' => $session->payment_status,
                'payment_method' => 'stripe',
            ]);

            $locked->forceFill([
                'status' => TransactionStatus::SUCCESSFUL,
                'paid_at' => now(),
                'gateway_reference' => $gatewayReference,
                'metadata' => $meta,
            ])->save();

            $locked->loadMissing('pledge');
            if ($locked->pledge !== null) {
                $this->pledgeBalance->refreshPledgeStatus($locked->pledge);
            }

            $this->receiptService->ensurePublicReceiptAccess($locked);

            SendDonationTaxReceiptEmailJob::dispatch($locked->uuid);
        });
    }

    private function markPendingFailed(Session $session): void
    {
        $transaction = $this->resolveTransaction($session);
        if ($transaction === null || $transaction->gateway !== 'stripe') {
            return;
        }

        if ($transaction->status !== TransactionStatus::PENDING) {
            return;
        }

        DB::transaction(function () use ($transaction, $session): void {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== TransactionStatus::PENDING || $locked->gateway !== 'stripe') {
                return;
            }

            if (
                is_string($locked->gateway_reference)
                && $locked->gateway_reference !== ''
                && $locked->gateway_reference !== $session->id
            ) {
                return;
            }

            $locked->forceFill([
                'status' => TransactionStatus::FAILED,
            ])->save();
        });
    }

    private function resolveTransaction(Session $session): ?Transaction
    {
        $uuid = $session->metadata['transaction_uuid'] ?? null;
        if (! is_string($uuid) || $uuid === '') {
            Log::warning('Stripe webhook: session missing transaction_uuid metadata.', [
                'session_id' => $session->id,
            ]);

            return null;
        }

        return Transaction::query()->where('uuid', $uuid)->first();
    }
}
