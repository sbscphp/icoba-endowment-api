<?php

namespace App\Services\Payment;

/**
 * Normalized CLNX / FCMB hosted-checkout payload (webhook + status API).
 *
 * Amount fields are kept apart on purpose:
 *  - originalAmount  what we asked CLNX to charge (should equal transactions.total_amount)
 *  - amount          what CLNX actually took from the donor (originalAmount + CLNX's own fees when donor-borne)
 *  - serviceFee / vatChargeFee / stampDutyChargeFee  CLNX's fee breakdown when provided
 * None of these ever overwrite the donation amount on our side.
 */
final readonly class FcmbCheckoutTransaction
{
    public function __construct(
        public string $invoiceRequestReference,
        public string $status,
        public ?string $transactionUuid,
        public ?string $reference = null,
        public ?float $originalAmount = null,
        public ?float $amount = null,
        public ?float $serviceFee = null,
        public ?float $vatChargeFee = null,
        public ?float $stampDutyChargeFee = null,
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
            originalAmount: self::number($data['originalAmount'] ?? null),
            amount: self::number($data['amount'] ?? null),
            serviceFee: self::number($data['serviceFee'] ?? null),
            vatChargeFee: self::number($data['vatChargeFee'] ?? null),
            stampDutyChargeFee: self::number($data['stampDutyChargeFee'] ?? null),
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

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    public function isPaid(): bool
    {
        return $this->status === 'SUCCESS';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }

    /**
     * The figure CLNX was asked to charge, i.e. what should match our total_amount.
     */
    public function chargedAmount(): ?float
    {
        return $this->originalAmount ?? $this->amount;
    }

    public function amountReceived(): ?float
    {
        return $this->amount ?? $this->originalAmount;
    }

    /**
     * CLNX's own fee: the explicit breakdown when present, otherwise amount - originalAmount.
     */
    public function gatewayFee(): ?float
    {
        $parts = array_filter([$this->serviceFee, $this->vatChargeFee, $this->stampDutyChargeFee], static fn (?float $v): bool => $v !== null);
        if ($parts !== []) {
            return round(array_sum($parts), 2);
        }

        if ($this->amount !== null && $this->originalAmount !== null && $this->amount > $this->originalAmount) {
            return round($this->amount - $this->originalAmount, 2);
        }

        return null;
    }
}
