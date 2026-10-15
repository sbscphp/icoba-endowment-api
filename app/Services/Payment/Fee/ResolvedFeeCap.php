<?php

namespace App\Services\Payment\Fee;

/**
 * A rule's cap expressed in the rule currency, plus how it got there, so the transaction
 * snapshot can show which rate turned "2,000 NGN" into "1.33 USD" on the day.
 */
final readonly class ResolvedFeeCap
{
    public function __construct(
        /** Cap in the rule currency, rounded to two decimals. */
        public float $amount,
        public string $currency,
        /** Cap as configured on the rule. */
        public float $sourceAmount,
        public string $sourceCurrency,
        /** How many units of the cap currency one unit of the rule currency buys (null when no conversion happened). */
        public ?float $exchangeRate,
        /** Date the rate was looked up for (null when no conversion happened). */
        public ?string $rateDate,
    ) {}

    public function converted(): bool
    {
        return $this->exchangeRate !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount' => number_format($this->sourceAmount, 2, '.', ''),
            'currency' => $this->sourceCurrency,
            'effective_amount' => number_format($this->amount, 2, '.', ''),
            'effective_currency' => $this->currency,
            'exchange_rate' => $this->exchangeRate !== null ? number_format($this->exchangeRate, 6, '.', '') : null,
            'rate_date' => $this->rateDate,
        ];
    }
}
