<?php

namespace App\Services\Payment;

/**
 * Primary Paystack payment confirmation path (webhook).
 *
 * Manual verification via PaystackCheckoutVerificationService is a secondary fallback
 * that reuses PaystackCheckoutSyncService — both paths are idempotent.
 */
final class PaystackWebhookService
{
    public function __construct(
        private readonly PaystackCheckoutSyncService $paystackCheckoutSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handlePayload(array $payload): void
    {
        $event = $payload['event'] ?? null;
        if (! is_string($event) || $event === '') {
            return;
        }

        /** @var array<string, mixed>|null $data */
        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            return;
        }

        $checkoutTransaction = PaystackCheckoutTransaction::fromPaystackData($data);

        match ($event) {
            'charge.success' => $this->paystackCheckoutSyncService->finalizeIfPaid($checkoutTransaction),
            'charge.failed' => $this->paystackCheckoutSyncService->markPendingFailed($checkoutTransaction),
            default => null,
        };
    }
}
