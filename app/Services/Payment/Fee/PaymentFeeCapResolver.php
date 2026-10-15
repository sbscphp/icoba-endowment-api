<?php

namespace App\Services\Payment\Fee;

use App\Models\PaymentFeeRule;
use App\Services\Currency\ExchangeRateService;
use Carbon\CarbonInterface;

/**
 * Expresses a rule's cap in the rule currency. A cap stored in another currency (the
 * endowment caps every rule at 2,000 NGN) is converted with the latest stored exchange
 * rate on the quote date, via NGN: cap × NGN-per-cap-unit ÷ NGN-per-rule-unit.
 */
final class PaymentFeeCapResolver
{
    public function __construct(private readonly ExchangeRateService $exchangeRates) {}

    public function resolve(PaymentFeeRule $rule, ?CarbonInterface $on = null): ?ResolvedFeeCap
    {
        if ($rule->cap_amount === null) {
            return null;
        }

        $capAmount = (float) $rule->cap_amount;
        if ($capAmount < 0) {
            return null;
        }

        $ruleCurrency = strtoupper(trim((string) $rule->currency));
        $capCurrency = $rule->capCurrency();

        if ($capCurrency === $ruleCurrency) {
            return new ResolvedFeeCap(
                amount: round($capAmount, 2),
                currency: $ruleCurrency,
                sourceAmount: $capAmount,
                sourceCurrency: $capCurrency,
                exchangeRate: null,
                rateDate: null,
            );
        }

        $on ??= now();
        $nairaPerCapUnit = $this->exchangeRates->rateForCurrencyOnDate($capCurrency, $on);
        $nairaPerRuleUnit = $this->exchangeRates->rateForCurrencyOnDate($ruleCurrency, $on);

        if ($nairaPerCapUnit <= 0 || $nairaPerRuleUnit <= 0) {
            // No usable rate: better to charge the uncapped percentage than a cap of zero.
            return null;
        }

        // Units of the cap currency per unit of the rule currency, e.g. 1500 NGN per USD.
        $rate = $nairaPerRuleUnit / $nairaPerCapUnit;

        return new ResolvedFeeCap(
            amount: round($capAmount / $rate, 2),
            currency: $ruleCurrency,
            sourceAmount: $capAmount,
            sourceCurrency: $capCurrency,
            exchangeRate: $rate,
            rateDate: $on->toDateString(),
        );
    }
}
