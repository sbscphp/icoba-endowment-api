<?php

namespace App\Services\Payment\Fee;

use App\Models\PaymentFeeRule;

/**
 * Result of pricing a donation: the donation itself, the service fee, and the total to charge.
 *
 * All figures are in the donation currency, rounded to two decimals.
 */
final readonly class PaymentFeeQuote
{
    public function __construct(
        public float $donationAmount,
        public float $feeAmount,
        public string $currency,
        public bool $chargesEnabled,
        public ?PaymentFeeRule $rule = null,
        /** The rule's cap in the quote currency, with the rate used when it was converted. */
        public ?ResolvedFeeCap $cap = null,
    ) {}

    public static function zero(float $donationAmount, string $currency, bool $chargesEnabled): self
    {
        return new self(
            donationAmount: round($donationAmount, 2),
            feeAmount: 0.0,
            currency: strtoupper($currency),
            chargesEnabled: $chargesEnabled,
        );
    }

    public function feeAmount(): float
    {
        return round($this->feeAmount, 2);
    }

    public function totalAmount(): float
    {
        return round($this->donationAmount + $this->feeAmount(), 2);
    }

    public function feeApplied(): bool
    {
        return $this->feeAmount() > 0;
    }

    /**
     * Frozen rule details stored on the transaction.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(): ?array
    {
        if ($this->rule === null) {
            return null;
        }

        return [
            'rule' => $this->rule->toSnapshot(),
            'cap' => $this->cap?->toArray(),
            'quoted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Column values for a transaction row.
     *
     * @return array<string, mixed>
     */
    public function toTransactionAttributes(): array
    {
        return [
            'fee_amount' => $this->feeAmount(),
            'total_amount' => $this->totalAmount(),
            'fee_rule_uuid' => $this->rule?->uuid,
            'fee_snapshot' => $this->snapshot(),
        ];
    }

    /**
     * Public API shape (quote endpoint, checkout response).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'donation_amount' => $this->money($this->donationAmount),
            'fee_amount' => $this->money($this->feeAmount()),
            'total_amount' => $this->money($this->totalAmount()),
            'currency' => $this->currency,
            'charges_enabled' => $this->chargesEnabled,
            'fee_applied' => $this->feeApplied(),
            'rule' => $this->rule !== null ? [
                'rule_id' => $this->rule->uuid,
                'name' => $this->rule->name,
                'percentage' => (string) $this->rule->percentage,
                'fixed_amount' => (string) $this->rule->fixed_amount,
                'cap_amount' => $this->rule->cap_amount !== null ? (string) $this->rule->cap_amount : null,
                'cap_currency' => $this->rule->cap_amount !== null ? ($this->cap?->sourceCurrency ?? $this->rule->currency) : null,
                // Cap in the quote currency after conversion (same as cap_amount when no conversion was needed).
                'effective_cap_amount' => $this->cap !== null ? $this->money($this->cap->amount) : null,
                'cap_exchange_rate' => $this->cap?->exchangeRate !== null ? number_format($this->cap->exchangeRate, 6, '.', '') : null,
            ] : null,
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
