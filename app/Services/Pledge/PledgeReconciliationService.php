<?php

namespace App\Services\Pledge;

use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class PledgeReconciliationService
{
    public function __construct(
        private readonly PledgeBalanceService $balanceService,
    ) {}

    /**
     * Link a payment transaction to a pledge and optionally supersede a placeholder row.
     */
    public function linkDonationToPledge(
        Transaction $payment,
        string $pledgeUuid,
        ?Transaction $placeholderToSupersede = null,
    ): void {
        DB::transaction(function () use ($payment, $pledgeUuid, $placeholderToSupersede): void {
            $payment->forceFill([
                'pledge_uuid' => $pledgeUuid,
                'application_type' => TransactionApplicationType::ADMIN_LINKED_PAYMENT,
            ])->save();

            if ($placeholderToSupersede !== null
                && $placeholderToSupersede->uuid !== $payment->uuid) {
                $placeholderToSupersede->forceFill([
                    'status' => TransactionStatus::SUPERSEDED,
                    'superseded_by_transaction_uuid' => $payment->uuid,
                ])->save();
            }

            $payment->load('pledge');
            if ($payment->pledge !== null) {
                $this->balanceService->refreshPledgeStatus($payment->pledge);
            }
        });
    }
}
