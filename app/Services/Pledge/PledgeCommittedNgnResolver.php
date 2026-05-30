<?php

namespace App\Services\Pledge;

use App\Enums\Currency;
use App\Services\Currency\ExchangeRateService;
use InvalidArgumentException;

final class PledgeCommittedNgnResolver
{
    /**
     * Snapshot NGN equivalent and FX multiplier (NGN per 1 unit of currency) at pledge capture.
     *
     * @return array{committed_amount_ngn: float, exchange_rate_to_naira: float}
     */
    public static function atCapture(float $committedAmount, string $currency): array
    {
        $enum = Currency::tryFrom(strtoupper(trim($currency)));
        if ($enum === null) {
            throw new InvalidArgumentException('Unsupported currency: '.$currency);
        }

        if ($enum === Currency::NGN) {
            return [
                'committed_amount_ngn' => round($committedAmount, 2),
                'exchange_rate_to_naira' => 1.0,
            ];
        }

        $rate = app(ExchangeRateService::class)->rateForCurrencyOnDate($enum->value, now());
        if ($rate <= 0) {
            throw new InvalidArgumentException('Invalid NGN rate for currency: '.$enum->value);
        }

        return [
            'committed_amount_ngn' => round($committedAmount * $rate, 2),
            'exchange_rate_to_naira' => round($rate, 6),
        ];
    }
}
