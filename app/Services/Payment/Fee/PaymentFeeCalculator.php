<?php

namespace App\Services\Payment\Fee;

use App\Models\PaymentFeeRule;

/**
 * Pure arithmetic: rule + donation amount -> fee. No database access.
 */
final class PaymentFeeCalculator
{
    /**
     * donation × percentage + fixed, then capped, rounded half-up to two decimals.
     *
     * @param  ResolvedFeeCap|null  $cap  the cap already expressed in the rule currency. Pass the
     *                                    resolver's result; leaving it out uses the rule's cap as-is,
     *                                    which is only right when the cap is in the rule currency.
     */
    public function fee(PaymentFeeRule $rule, float $donationAmount, ?ResolvedFeeCap $cap = null): float
    {
        if ($donationAmount <= 0) {
            return 0.0;
        }

        $rate = max(0.0, (float) $rule->percentage) / 100;
        $fee = $donationAmount * $rate + (float) $rule->fixed_amount;

        if ($fee < 0) {
            $fee = 0.0;
        }

        $capAmount = $cap?->amount ?? ($rule->cap_amount !== null ? (float) $rule->cap_amount : null);
        if ($capAmount !== null && $capAmount >= 0 && $fee > $capAmount) {
            $fee = $capAmount;
        }

        return round($fee, 2, PHP_ROUND_HALF_UP);
    }
}
