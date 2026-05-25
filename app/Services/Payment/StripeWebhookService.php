<?php

namespace App\Services\Payment;

use Stripe\Event;

/**
 * Primary Stripe payment confirmation path (webhook).
 *
 * Manual verification via StripeCheckoutVerificationService is a secondary fallback
 * that reuses StripeCheckoutSyncService — both paths are idempotent.
 */
final class StripeWebhookService
{
    public function __construct(
        private readonly StripeCheckoutSyncService $stripeCheckoutSyncService,
    ) {}

    public function handleEvent(Event $event): void
    {
        match ($event->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->stripeCheckoutSyncService->finalizeIfPaid($event->data->object),
            'checkout.session.async_payment_failed', 'checkout.session.expired' => $this->stripeCheckoutSyncService->markPendingFailed($event->data->object),
            default => null,
        };
    }
}
