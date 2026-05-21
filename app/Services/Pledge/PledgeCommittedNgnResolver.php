<?php

namespace App\Services\Pledge;

use App\Enums\Currency;
use InvalidArgumentException;

final class PledgeCommittedNgnResolver
{
    /**
     * Snapshot NGN equivalent and FX multiplier (NGN per 1 unit of currency) at pledge capture.
     * Rates use Currency::referenceNairaRatePerUnit() until a dedicated FX converter exists.
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

        $rate = $enum->referenceNairaRatePerUnit();
        if ($rate <= 0) {
            throw new InvalidArgumentException('Invalid reference NGN rate for currency: '.$enum->value);
        }

        return [
            'committed_amount_ngn' => round($committedAmount * $rate, 2),
            'exchange_rate_to_naira' => round($rate, 6),
        ];
    }
}
