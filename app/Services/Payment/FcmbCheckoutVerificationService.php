<?php

namespace App\Services\Payment;

use App\Enums\TransactionStatus;
use App\Exceptions\ApiException;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\Transaction\TransactionService;
use App\Services\Transaction\TransactionNgnSnapshotService;
use RuntimeException;

final class FcmbCheckoutVerificationService
{
    public function __construct(
        private readonly FcmbCheckoutService $fcmbCheckoutService,
        private readonly FcmbCheckoutSyncService $fcmbCheckoutSyncService,
        private readonly TransactionService $transactionService,
        private readonly TransactionNgnSnapshotService $transactionNgnSnapshot,
        private readonly CheckoutVerificationReceiptResolver $receiptResolver,
    ) {}

    /**
     * @return array{
     *     checkout_session_id: string,
     *     payment_status: string,
     *     session_status: string,
     *     sync_action: string,
     *     receipt_number: string|null,
     *     transaction: Transaction
     * }
     */
    public function verify(
        string $checkoutSessionId,
        ?User $user = null,
        ?string $transactionUuid = null,
    ): array {
        try {
            $checkoutTransaction = $this->fcmbCheckoutService->retrieveCheckoutTransaction($checkoutSessionId);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            if (str_contains(strtolower($message), 'invalid')) {
                throw new ApiException('Invalid FCMB checkout reference.', 404);
            }

            throw new ApiException('Unable to verify FCMB checkout reference.', 502, [
                'fcmb_error' => $message,
            ]);
        }

        $transactionBefore = $this->fcmbCheckoutSyncService->resolveTransaction($checkoutTransaction);
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
            $this->fcmbCheckoutSyncService->finalizeIfPaid($checkoutTransaction);
        } elseif ($syncAction === 'will_mark_failed') {
            $this->fcmbCheckoutSyncService->markPendingFailed($checkoutTransaction);
        }

        $transaction = $this->transactionService->findTransaction($transactionBefore->uuid);
        $this->transactionNgnSnapshot->backfillIfMissing($transaction);
        $transaction = $this->transactionService->findTransaction($transactionBefore->uuid);
        $syncAction = $this->resolveSyncActionAfter($syncAction, $transaction);

        $paymentStatus = $this->mapPaymentStatus($checkoutTransaction);

        return [
            'checkout_session_id' => $checkoutTransaction->invoiceRequestReference,
            'payment_status' => $paymentStatus,
            'session_status' => $this->mapSessionStatus($checkoutTransaction),
            'sync_action' => $syncAction,
            'receipt_number' => $this->receiptResolver->resolve($paymentStatus, $transaction),
            'transaction' => $transaction,
        ];
    }

    private function resolveSyncAction(FcmbCheckoutTransaction $checkoutTransaction, Transaction $transaction): string
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

    private function mapPaymentStatus(FcmbCheckoutTransaction $checkoutTransaction): string
    {
        return match (true) {
            $checkoutTransaction->isPaid() => 'paid',
            $checkoutTransaction->isFailed() => 'unpaid',
            default => 'pending',
        };
    }

    private function mapSessionStatus(FcmbCheckoutTransaction $checkoutTransaction): string
    {
        return match (true) {
            $checkoutTransaction->isPaid() => 'complete',
            $checkoutTransaction->isFailed() => 'expired',
            default => 'open',
        };
    }
}
