<?php

namespace App\Services\Payment;

use App\Enums\TransactionStatus;
use App\Exceptions\ApiException;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\Transaction\TransactionService;
use App\Services\Transaction\TransactionNgnSnapshotService;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;

final class StripeCheckoutVerificationService
{
    public function __construct(
        private readonly StripeCheckoutService $stripeCheckoutService,
        private readonly StripeCheckoutSyncService $stripeCheckoutSyncService,
        private readonly TransactionService $transactionService,
        private readonly TransactionNgnSnapshotService $transactionNgnSnapshot,
    ) {}

    /**
     * Secondary confirmation path (Paystack-style verify).
     *
     * Webhooks remain the primary source of truth. This endpoint re-fetches the Checkout
     * Session and applies the same idempotent sync logic when the client lands on the
     * success page before the webhook arrives.
     *
     * @return array{
     *     checkout_session_id: string,
     *     payment_status: string,
     *     session_status: string,
     *     sync_action: string,
     *     transaction: \App\Models\Transaction
     * }
     */
    public function verify(
        string $checkoutSessionId,
        ?User $user = null,
        ?string $transactionUuid = null,
    ): array {
        try {
            $session = $this->stripeCheckoutService->retrieveCheckoutSession($checkoutSessionId);
        } catch (InvalidRequestException) {
            throw new ApiException('Invalid Stripe checkout session.', 404);
        } catch (ApiErrorException $e) {
            throw new ApiException('Unable to verify Stripe checkout session.', 502, [
                'stripe_error' => $e->getMessage(),
            ]);
        }

        $transactionBefore = $this->stripeCheckoutSyncService->resolveTransaction($session);
        if ($transactionBefore === null) {
            throw new ApiException('Transaction not found for this checkout session.', 404);
        }

        if ($transactionUuid !== null && $transactionBefore->uuid !== $transactionUuid) {
            throw new ApiException('Checkout session does not match the provided transaction.', 422);
        }

        if ($user !== null && $transactionBefore->user_uuid !== null && $transactionBefore->user_uuid !== $user->uuid) {
            throw new ApiException('This checkout session is not linked to your account.', 403);
        }

        $syncAction = $this->resolveSyncAction($session, $transactionBefore);

        if ($syncAction === 'will_finalize') {
            $this->stripeCheckoutSyncService->finalizeIfPaid($session);
        } elseif ($syncAction === 'will_mark_failed') {
            $this->stripeCheckoutSyncService->markPendingFailed($session);
        }

        $transaction = $this->transactionService->findTransaction($transactionBefore->uuid);
        $this->transactionNgnSnapshot->backfillIfMissing($transaction);
        $transaction = $this->transactionService->findTransaction($transactionBefore->uuid);
        $syncAction = $this->resolveSyncActionAfter($syncAction, $transaction);

        return [
            'checkout_session_id' => $session->id,
            'payment_status' => (string) $session->payment_status,
            'session_status' => (string) $session->status,
            'sync_action' => $syncAction,
            'transaction' => $transaction,
        ];
    }

    private function resolveSyncAction(Session $session, Transaction $transaction): string
    {
        if ($transaction->status === TransactionStatus::SUCCESSFUL) {
            return 'already_finalized';
        }

        if ($transaction->status === TransactionStatus::FAILED) {
            return 'already_failed';
        }

        if ($session->payment_status === 'paid') {
            return 'will_finalize';
        }

        if ($session->status === 'expired') {
            return 'will_mark_failed';
        }

        return 'pending';
    }

    private function resolveSyncActionAfter(string $syncAction, Transaction $transaction): string
    {
        return match ($syncAction) {
            'will_finalize' => $transaction->status === TransactionStatus::SUCCESSFUL
                ? 'finalized'
                : 'already_finalized',
            'will_mark_failed' => $transaction->status === TransactionStatus::FAILED
                ? 'marked_failed'
                : 'already_failed',
            default => $syncAction,
        };
    }
}
