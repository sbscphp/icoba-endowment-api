<?php

namespace App\Services\Pledge;

use App\Enums\PledgeStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Pledge;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PledgeBalanceService
{
    /**
     * Sum of successful, non-superseded linked payments counting toward the pledge.
     */
    public function fulfilledAmount(Pledge $pledge): string
    {
        $sum = $this->basePaymentQuery($pledge)->sum('amount');

        return (string) ($sum ?? '0');
    }

    /**
     * Remaining commitment in pledge currency (numeric string).
     */
    public function remainingAmount(Pledge $pledge): string
    {
        $fulfilled = (float) $this->fulfilledAmount($pledge);
        $committed = (float) $pledge->committed_amount;
        $remaining = max(0, $committed - $fulfilled);

        return number_format($remaining, 2, '.', '');
    }

    /**
     * @return Builder<Transaction>
     */
    public function basePaymentQuery(Pledge $pledge): Builder
    {
        return Transaction::query()
            ->where('pledge_uuid', $pledge->uuid)
            ->where('status', TransactionStatus::SUCCESSFUL)
            ->where(function (Builder $b): void {
                $b->whereNull('application_type')
                    ->orWhereNotIn('application_type', [
                        TransactionApplicationType::PLEDGE_PLACEHOLDER->value,
                    ]);
            });
    }

    public function refreshPledgeStatus(Pledge $pledge): void
    {
        if ($pledge->status !== PledgeStatus::ACTIVE) {
            return;
        }

        $remaining = (float) $this->remainingAmount($pledge);
        if ($remaining <= 0.00001) {
            $pledge->update([
                'status' => PledgeStatus::FULFILLED,
                'fulfilled_at' => now(),
            ]);
        }
    }

    /**
     * Apply v1 overpayment split into transaction metadata (NGN allocation uses amount_in_naira).
     *
     * @return array<string, mixed>
     */
    public function buildOverpaymentMetadata(Pledge $pledge, float $paymentAmount, ?float $paymentAmountNgn): array
    {
        $remaining = (float) $this->remainingAmount($pledge);
        $toPledge = min($paymentAmount, $remaining);
        $surplus = max(0, $paymentAmount - $toPledge);

        $meta = [
            'pledge_allocation_amount' => number_format($toPledge, 2, '.', ''),
            'campaign_credit_amount' => number_format($surplus, 2, '.', ''),
        ];

        if ($paymentAmountNgn !== null) {
            $rate = $paymentAmount > 0 ? $paymentAmountNgn / $paymentAmount : 0;
            $remainingNgn = $remaining * $rate;
            $toPledgeNgn = min($paymentAmountNgn, $remainingNgn);
            $surplusNgn = max(0, $paymentAmountNgn - $toPledgeNgn);
            $meta['pledge_allocation_amount_ngn'] = number_format($toPledgeNgn, 2, '.', '');
            $meta['campaign_credit_amount_ngn'] = number_format($surplusNgn, 2, '.', '');
        }

        return $meta;
    }

    /**
     * NGN amount attributed to pledge fulfillment for reporting (uses metadata if present).
     */
    public function pledgeAttributedNgn(Transaction $transaction): float
    {
        $meta = $transaction->metadata ?? [];
        if (isset($meta['pledge_allocation_amount_ngn'])) {
            return (float) $meta['pledge_allocation_amount_ngn'];
        }
        if ($transaction->pledge_uuid !== null && $transaction->status === TransactionStatus::SUCCESSFUL) {
            return (float) ($transaction->amount_in_naira ?? 0);
        }

        return 0.0;
    }

    public function ensureReceiptToken(Transaction $transaction): string
    {
        if ($transaction->receipt_token !== null && $transaction->receipt_token !== '') {
            return $transaction->receipt_token;
        }
        $token = Str::random(48);
        $transaction->forceFill(['receipt_token' => $token])->save();

        return $token;
    }
}
