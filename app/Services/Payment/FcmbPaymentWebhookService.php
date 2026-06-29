<?php

namespace App\Services\Payment;

final class FcmbPaymentWebhookService
{
    public function __construct(
        private readonly FcmbCheckoutSyncService $fcmbCheckoutSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handlePayload(array $payload): void
    {
        $checkoutTransaction = FcmbCheckoutTransaction::fromClnxData($payload);

        if ($checkoutTransaction->isPaid()) {
            $this->fcmbCheckoutSyncService->finalizeIfPaid($checkoutTransaction);
        } elseif ($checkoutTransaction->isFailed()) {
            $this->fcmbCheckoutSyncService->markPendingFailed($checkoutTransaction);
        }
    }
}
