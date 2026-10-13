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
     */
    public function fee(PaymentFeeRule $rule, float $donationAmount): float
    {
        if ($donationAmount <= 0) {
            return 0.0;
        }

        $rate = max(0.0, (float) $rule->percentage) / 100;
        $fee = $donationAmount * $rate + (float) $rule->fixed_amount;

        if ($fee < 0) {
            $fee = 0.0;
        }

        $cap = $rule->cap_amount;
        if ($cap !== null && (float) $cap >= 0 && $fee > (float) $cap) {
            $fee = (float) $cap;
        }

        return round($fee, 2, PHP_ROUND_HALF_UP);
    }
}
