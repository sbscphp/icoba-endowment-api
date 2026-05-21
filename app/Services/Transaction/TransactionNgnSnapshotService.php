<?php

namespace App\Services\Transaction;

use App\Models\Transaction;
use App\Services\Pledge\PledgeCommittedNgnResolver;

final class TransactionNgnSnapshotService
{
    /**
     * @return array{exchange_rate_to_naira: float, amount_in_naira: float}
     */
    public function resolve(float $amount, string $currency, ?float $explicitRate = null): array
    {
        if ($explicitRate !== null) {
            return [
                'exchange_rate_to_naira' => round($explicitRate, 6),
                'amount_in_naira' => round($amount * $explicitRate, 2),
            ];
        }

        $ngn = PledgeCommittedNgnResolver::atCapture($amount, $currency);

        return [
            'exchange_rate_to_naira' => $ngn['exchange_rate_to_naira'],
            'amount_in_naira' => $ngn['committed_amount_ngn'],
        ];
    }

    public function backfillIfMissing(Transaction $transaction): Transaction
    {
        if ($transaction->amount_in_naira !== null && $transaction->exchange_rate_to_naira !== null) {
            return $transaction;
        }

        $snapshot = $this->resolve((float) $transaction->amount, (string) $transaction->currency);
        $updates = [];

        if ($transaction->amount_in_naira === null) {
            $updates['amount_in_naira'] = $snapshot['amount_in_naira'];
        }

        if ($transaction->exchange_rate_to_naira === null) {
            $updates['exchange_rate_to_naira'] = $snapshot['exchange_rate_to_naira'];
        }

        if ($updates === []) {
            return $transaction;
        }

        $transaction->forceFill($updates)->save();
        $transaction->refresh();

        return $transaction;
    }
}
