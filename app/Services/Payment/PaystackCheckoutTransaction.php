<?php

namespace App\Services\Payment;

/**
 * Normalized Paystack transaction payload (mirrors Stripe Checkout Session usage in sync/verify).
 */
final readonly class PaystackCheckoutTransaction
{
    public function __construct(
        public string $reference,
        public string $status,
        public ?string $transactionUuid,
        public ?int $paystackTransactionId = null,
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
}
