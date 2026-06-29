<?php

namespace App\Services\Payment;

/**
 * Normalized CLNX / FCMB hosted-checkout payload (webhook + status API).
 */
final readonly class FcmbCheckoutTransaction
{
    public function __construct(
        public string $invoiceRequestReference,
        public string $status,
        public ?string $transactionUuid,
        public ?string $reference = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromClnxData(array $data): self
    {
        $transactionUuid = self::extractTransactionUuid($data);

        return new self(
            invoiceRequestReference: (string) ($data['invoiceRequestReference'] ?? $data['requestReference'] ?? ''),
            status: strtoupper((string) ($data['status'] ?? 'PENDING')),
            transactionUuid: $transactionUuid,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function extractTransactionUuid(array $data): ?string
    {
        $customFields = $data['customFields'] ?? null;
        if (! is_array($customFields)) {
            return null;
        }

        foreach ($customFields as $field) {
            if (! is_array($field)) {
                continue;
            }

            if (($field['label'] ?? null) === 'transaction_uuid') {
                $value = $field['value'] ?? null;

                return is_string($value) && $value !== '' ? $value : null;
            }
        }

        return null;
    }

    public function isPaid(): bool
    {
        return $this->status === 'SUCCESS';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }
}
