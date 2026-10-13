<?php

namespace App\Services\Payment\Fee;

use App\Models\PaymentFeeRule;

/**
 * Finds the single active rule for the currency whose band covers the amount.
 */
final class PaymentFeeRuleResolver
{
    public function resolve(string $currency, float $amount): ?PaymentFeeRule
    {
        if ($amount <= 0) {
            return null;
        }

        return PaymentFeeRule::query()
            ->active()
            ->forCurrency($currency)
            ->coveringAmount($amount)
            // Bands must not overlap (enforced on write); if they ever do, prefer the tightest lower bound.
            ->orderByDesc('min_amount')
            ->orderBy('id')
            ->first();
    }
}
