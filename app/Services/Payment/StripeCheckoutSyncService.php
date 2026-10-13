<?php

namespace App\Services\Payment;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Transaction\TransactionFinalizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;

final class StripeCheckoutSyncService
{
    public function __construct(
        private readonly TransactionFinalizationService $finalizationService,
        private readonly GatewaySettlementContext $settlementContext,
    ) {}

    /**
     * Best-effort Stripe fee lookup. Resolved lazily so this service stays usable without a
     * configured Stripe secret (tests, non-Stripe webhooks).
     *
     * @return array{fee: float|null, fee_other_currency: float|null, currency: string|null}
     */
    private function resolveStripeFee(Session $session, string $transactionCurrency): array
    {
        $none = ['fee' => null, 'fee_other_currency' => null, 'currency' => null];

        try {
            $result = app(StripeCheckoutService::class)->resolveGatewayFee($session);
        } catch (\Throwable) {
            return $none;
        }

        if ($result['fee'] === null || $result['currency'] === null) {
            return $none;
        }

        if (strtoupper($result['currency']) === strtoupper($transactionCurrency)) {
            return ['fee' => $result['fee'], 'fee_other_currency' => null, 'currency' => $result['currency']];
        }

        return ['fee' => null, 'fee_other_currency' => $result['fee'], 'currency' => $result['currency']];
    }

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

        if (! $this->sessionMatchesTransaction($transaction, $session)) {
            Log::warning('Stripe checkout sync: session does not match transaction.', [
                'transaction_uuid' => $transaction->uuid,
                'session_id' => $session->id,
                'gateway_reference' => $transaction->gateway_reference,
            ]);

            return false;
        }

        // Stripe's amount_total and balance fee land in the gateway_* columns only; the donation amount is untouched.
        $currency = (string) $transaction->currency;
        $received = isset($session->amount_total) ? MinorUnits::toMajor($session->amount_total, $currency) : null;
        $fee = $this->resolveStripeFee($session, $currency);
        $settlement = $this->settlementContext->build($transaction, $received, $received, $fee['fee']);

        $extraMeta = [];
        if ($fee['fee_other_currency'] !== null) {
            $extraMeta['stripe_balance_fee'] = $fee['fee_other_currency'];
            $extraMeta['stripe_balance_fee_currency'] = $fee['currency'];
        }

        return $this->finalizationService->finalizeSuccessful($transaction, array_merge($settlement, [
            'gateway_reference' => $gatewayReference,
            'metadata' => array_merge($settlement['metadata'], $extraMeta, [
                'stripe_checkout_session_id' => $session->id,
                'stripe_payment_intent_id' => $session->payment_intent,
                'stripe_payment_status' => $session->payment_status,
                'payment_method' => 'stripe',
                'stripe_checkout_finalized_at' => now()->toIso8601String(),
            ]),
            'tax_receipt_email_meta_key' => 'stripe_tax_receipt_email_queued',
        ]));
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

        if (str_starts_with($uuid, StripeCheckoutService::PLATFORM_PREFIX)) {
            $uuid = substr($uuid, strlen(StripeCheckoutService::PLATFORM_PREFIX));
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
