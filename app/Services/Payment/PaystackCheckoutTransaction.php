<?php

namespace App\Services\Payment;

/**
 * Normalized Paystack transaction payload (mirrors Stripe Checkout Session usage in sync/verify).
 *
 * Paystack reports `amount` and `fees` in minor units (kobo / cents). They are converted here and
 * only ever land in the gateway_* columns, never in the donation amount.
 */
final readonly class PaystackCheckoutTransaction
{
    public function __construct(
        public string $reference,
        public string $status,
        public ?string $transactionUuid,
        public ?int $paystackTransactionId = null,
        public ?string $currency = null,
        public ?int $amountMinor = null,
        public ?int $feesMinor = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromPaystackData(array $data): self
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $transactionUuid = $metadata['transaction_uuid'] ?? null;

        return new self(
            reference: (string) ($data['reference'] ?? ''),
            status: (string) ($data['status'] ?? 'pending'),
            transactionUuid: is_string($transactionUuid) && $transactionUuid !== '' ? $transactionUuid : null,
            paystackTransactionId: isset($data['id']) ? (int) $data['id'] : null,
            currency: isset($data['currency']) && is_string($data['currency']) ? strtoupper($data['currency']) : null,
            amountMinor: isset($data['amount']) && is_numeric($data['amount']) ? (int) $data['amount'] : null,
            feesMinor: isset($data['fees']) && is_numeric($data['fees']) ? (int) $data['fees'] : null,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'abandoned', 'reversed'], true);
    }

    public function amountReceived(string $fallbackCurrency): ?float
    {
        return MinorUnits::toMajor($this->amountMinor, $this->currency ?? $fallbackCurrency);
    }

    public function gatewayFee(string $fallbackCurrency): ?float
    {
        return MinorUnits::toMajor($this->feesMinor, $this->currency ?? $fallbackCurrency);
    }
}
