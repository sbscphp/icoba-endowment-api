<?php

namespace App\Services\Transaction;

use App\Enums\TransactionStatus;
use App\Jobs\EvaluateDonorTierRecognitionJob;
use App\Jobs\SendDonationConfirmationEmailJob;
use App\Jobs\SendDonationTaxReceiptEmailJob;
use App\Models\Transaction;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Receipt\ReceiptService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Centralized post-success transition for any payment path: Stripe, Paystack,
 * admin-manual reconciliation, FCMB CSV import, and the future FCMB webhook.
 */
final class TransactionFinalizationService
{
    public function __construct(
        private readonly PledgeBalanceService $pledgeBalance,
        private readonly ReceiptService $receiptService,
        private readonly TransactionNgnSnapshotService $transactionNgnSnapshot,
    ) {}

    /**
     * Apply success state transitions, ensure receipt access, dispatch emails + tier evaluation.
     *
     * Idempotent — does nothing if the row is not in PENDING status anymore.
     *
     * @param  array{
     *     gateway_reference?: ?string,
     *     fcmb_statement_reference?: ?string,
     *     paid_at?: ?CarbonInterface,
     *     paid_into_account_number?: ?string,
     *     reconciled_by_admin_uuid?: ?string,
     *     reconciliation_note?: ?string,
     *     metadata?: array<string, mixed>,
     *     confirmation_email_meta_key?: ?string,
     *     tax_receipt_email_meta_key?: ?string,
     *     suppress_emails?: bool,
     *     ngn_rate_date?: ?CarbonInterface,
     * }  $context
     */
    public function finalizeSuccessful(Transaction $transaction, array $context = []): bool
    {
        if ($transaction->status !== TransactionStatus::PENDING) {
            return false;
        }

        return (bool) DB::transaction(function () use ($transaction, $context): bool {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== TransactionStatus::PENDING) {
                return false;
            }

            $metadataInput = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];
            $confirmationKey = $context['confirmation_email_meta_key'] ?? 'donation_confirmation_email_queued';
            $taxReceiptKey = $context['tax_receipt_email_meta_key'] ?? 'tax_receipt_email_queued';
            $suppressEmails = (bool) ($context['suppress_emails'] ?? false);

            $meta = is_array($locked->metadata) ? $locked->metadata : [];
            $meta = array_merge($meta, $metadataInput);

            $shouldQueueConfirmationEmail = ! $suppressEmails && ! array_key_exists($confirmationKey, $meta);
            $shouldQueueTaxReceiptEmail = ! $suppressEmails && ! array_key_exists($taxReceiptKey, $meta);

            if ($shouldQueueConfirmationEmail) {
                $meta[$confirmationKey] = true;
            }
            if ($shouldQueueTaxReceiptEmail) {
                $meta[$taxReceiptKey] = true;
            }

            $paidAt = $context['paid_at'] ?? null;
            $paidAt = $paidAt instanceof CarbonInterface ? $paidAt : ($paidAt !== null ? Carbon::parse((string) $paidAt) : null);
            $effectivePaidAt = $locked->paid_at ?? $paidAt ?? now();

            $rateDate = $context['ngn_rate_date'] ?? $paidAt ?? $locked->paid_at ?? $locked->created_at ?? now();
            $rateDate = $rateDate instanceof CarbonInterface ? $rateDate : Carbon::parse((string) $rateDate);

            $ngnFields = [];
            if ($locked->amount_in_naira === null || $locked->exchange_rate_to_naira === null) {
                $snapshot = $this->transactionNgnSnapshot->resolveAtDate(
                    (float) $locked->amount,
                    (string) $locked->currency,
                    $rateDate,
                );

                if ($locked->amount_in_naira === null) {
                    $ngnFields['amount_in_naira'] = $snapshot['amount_in_naira'];
                }
                if ($locked->exchange_rate_to_naira === null) {
                    $ngnFields['exchange_rate_to_naira'] = $snapshot['exchange_rate_to_naira'];
                }
            }

            $update = [
                'status' => TransactionStatus::SUCCESSFUL,
                'paid_at' => $effectivePaidAt,
                'metadata' => $meta,
                'reconciled_at' => $locked->reconciled_at ?? now(),
            ];

            if (array_key_exists('gateway_reference', $context) && $context['gateway_reference'] !== null && $context['gateway_reference'] !== '') {
                $update['gateway_reference'] = (string) $context['gateway_reference'];
            }
            if (array_key_exists('fcmb_statement_reference', $context) && $context['fcmb_statement_reference'] !== null && $context['fcmb_statement_reference'] !== '') {
                $update['fcmb_statement_reference'] = (string) $context['fcmb_statement_reference'];
            }
            if (array_key_exists('paid_into_account_number', $context) && $context['paid_into_account_number'] !== null && $context['paid_into_account_number'] !== '') {
                $update['paid_into_account_number'] = (string) $context['paid_into_account_number'];
            }
            if (array_key_exists('reconciled_by_admin_uuid', $context) && $context['reconciled_by_admin_uuid'] !== null) {
                $update['reconciled_by_admin_uuid'] = (string) $context['reconciled_by_admin_uuid'];
            }
            if (array_key_exists('reconciliation_note', $context) && $context['reconciliation_note'] !== null && $context['reconciliation_note'] !== '') {
                $update['reconciliation_note'] = (string) $context['reconciliation_note'];
            }

            $locked->forceFill(array_merge($update, $ngnFields))->save();

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
}
