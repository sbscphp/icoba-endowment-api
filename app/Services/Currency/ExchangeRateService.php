<?php

namespace App\Services\Currency;

use App\Enums\Currency;
use App\Models\ExchangeRate;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ExchangeRateService
{
    /**
     * NGN per 1 unit of currency on the supplied date (or nearest prior).
     *
     * Falls back to the static reference rate on Currency enum when no row exists.
     */
    public function rateForCurrencyOnDate(string $currency, CarbonInterface $date): float
    {
        $currency = strtoupper(trim($currency));
        $enum = Currency::tryFrom($currency);

        if ($enum === Currency::NGN) {
            return 1.0;
        }

        $effectiveDate = $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();

        $exact = ExchangeRate::query()
            ->where('currency', $currency)
            ->whereDate('effective_date', $effectiveDate->toDateString())
            ->orderByDesc('effective_date')
            ->first();

        if ($exact !== null) {
            return (float) $exact->rate_to_naira;
        }

        $prior = ExchangeRate::query()
            ->where('currency', $currency)
            ->whereDate('effective_date', '<=', $effectiveDate->toDateString())
            ->orderByDesc('effective_date')
            ->first();

        if ($prior !== null) {
            return (float) $prior->rate_to_naira;
        }

        if ($enum !== null) {
            return (float) $enum->referenceNairaRatePerUnit();
        }

        return 1.0;
    }
}
