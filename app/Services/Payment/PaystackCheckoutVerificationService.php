<?php

namespace App\Services\Payment;

use App\Enums\TransactionStatus;
use App\Exceptions\ApiException;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\Transaction\TransactionService;
use App\Services\Transaction\TransactionNgnSnapshotService;
use RuntimeException;

final class PaystackCheckoutVerificationService
{
    public function __construct(
        private readonly PaystackCheckoutService $paystackCheckoutService,
        private readonly PaystackCheckoutSyncService $paystackCheckoutSyncService,
        private readonly TransactionService $transactionService,
        private readonly TransactionNgnSnapshotService $transactionNgnSnapshot,
    ) {}

    /**
     * Secondary confirmation path.
     *
     * Webhooks remain the primary source of truth. This endpoint re-fetches the Paystack
     * transaction and applies the same idempotent sync logic when the client lands on the
     * success page before the webhook arrives.
     *
     * @return array{
     *     checkout_session_id: string,
     *     payment_status: string,
     *     session_status: string,
     *     sync_action: string,
     *     transaction: Transaction
     * }
     */
    public function verify(
        string $checkoutSessionId,
        ?User $user = null,
        ?string $transactionUuid = null,
    ): array {
        try {
            $checkoutTransaction = $this->paystackCheckoutService->retrieveCheckoutTransaction($checkoutSessionId);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            if (str_contains(strtolower($message), 'invalid')) {
                throw new ApiException('Invalid Paystack checkout reference.', 404);
            }

            throw new ApiException('Unable to verify Paystack checkout reference.', 502, [
                'paystack_error' => $message,
            ]);
        }

        $transactionBefore = $this->paystackCheckoutSyncService->resolveTransaction($checkoutTransaction);
        if ($transactionBefore === null) {
            throw new ApiException('Transaction not found for this checkout reference.', 404);
        }

        if ($transactionUuid !== null && $transactionBefore->uuid !== $transactionUuid) {
            throw new ApiException('Checkout reference does not match the provided transaction.', 422);
        }

        if ($user !== null && $transactionBefore->user_uuid !== null && $transactionBefore->user_uuid !== $user->uuid) {
            throw new ApiException('This checkout reference is not linked to your account.', 403);
        }

        $syncAction = $this->resolveSyncAction($checkoutTransaction, $transactionBefore);

        if ($syncAction === 'will_finalize') {
            $this->paystackCheckoutSyncService->finalizeIfPaid($checkoutTransaction);
        } elseif ($syncAction === 'will_mark_failed') {
            $this->paystackCheckoutSyncService->markPendingFailed($checkoutTransaction);
        }

        $transaction = $this->transactionService->findTransaction($transactionBefore->uuid);
        $this->transactionNgnSnapshot->backfillIfMissing($transaction);
        $transaction = $this->transactionService->findTransaction($transactionBefore->uuid);
        $syncAction = $this->resolveSyncActionAfter($syncAction, $transaction);

        return [
            'checkout_session_id' => $checkoutTransaction->reference,
            'payment_status' => $this->mapPaymentStatus($checkoutTransaction),
            'session_status' => $this->mapSessionStatus($checkoutTransaction),
            'sync_action' => $syncAction,
            'transaction' => $transaction,
        ];
    }

    private function resolveSyncAction(PaystackCheckoutTransaction $checkoutTransaction, Transaction $transaction): string
    {
        if ($transaction->status === TransactionStatus::SUCCESSFUL) {
            return 'already_finalized';
        }

        if ($transaction->status === TransactionStatus::FAILED) {
            return 'already_failed';
        }

        if ($checkoutTransaction->isPaid()) {
            return 'will_finalize';
        }

        if ($checkoutTransaction->isFailed()) {
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

    private function mapPaymentStatus(PaystackCheckoutTransaction $checkoutTransaction): string
    {
        return match (true) {
            $checkoutTransaction->isPaid() => 'paid',
            $checkoutTransaction->isFailed() => 'unpaid',
            default => 'pending',
        };
    }

    private function mapSessionStatus(PaystackCheckoutTransaction $checkoutTransaction): string
    {
        return match ($checkoutTransaction->status) {
            'success' => 'complete',
            'failed', 'abandoned', 'reversed' => 'expired',
            default => 'open',
        };
    }
}
