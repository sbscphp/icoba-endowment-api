<?php

namespace App\Http\Resources;

use App\Models\TierConfiguration;
use App\Models\Transaction;
use App\Services\Receipt\ReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $set = $this->donor?->graduationSet;
        /** @var TierConfiguration|null $tier */
        $tier = $this->resource->getAttribute('matched_tier');

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $receiptLinks = $this->resolveReceiptLinks();

        return [
            'transaction_uuid' => $this->uuid,
            'transaction_id' => $this->transaction_id,
            'transaction_date' => $this->paid_at?->toDateString() ?? $this->created_at?->toDateString(),
            'transaction_time' => $this->paid_at?->toTimeString() ?? $this->created_at?->toTimeString(),
            'paid_at' => $this->paid_at,
            // 'created_at' => $this->created_at,
            // 'updated_at' => $this->updated_at,
            'donor_name' => $this->resolveDonorName(),
            'donor_email' => $this->donor_email ?? $this->donor?->email,
            'donor_phone' => $this->donor_phone ?? $this->donor?->phone_number,
            'is_anonymous' => (bool) $this->is_anonymous,
            'linked_campaign' => $this->campaign !== null ? [
                'campaign_id' => $this->campaign->uuid,
                'public_campaign_code' => $this->campaign->campaign_id,
                'name' => $this->campaign->name,
            ] : null,
            'donor_tier' => $tier !== null ? [
                'tier_id' => $tier->uuid,
                'name' => $tier->name,
            ] : null,
            'donor_set' => $set !== null ? [
                'graduation_set_id' => $set->uuid,
                'name' => $set->name,
                'set_number' => $set->set_number,
            ] : null,
            'donation_type' => isset($metadata['donation_type']) && $metadata['donation_type'] !== ''
                ? (string) $metadata['donation_type']
                : 'One Time Donation',
            'payment_method' => isset($metadata['payment_method']) && $metadata['payment_method'] !== ''
                ? (string) $metadata['payment_method']
                : null,
            'payment_via' => $this->gateway,
            'gateway_reference' => $this->gateway_reference,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'amount_in_naira' => $this->amount_in_naira !== null ? (string) $this->amount_in_naira : null,
            'exchange_rate_to_naira' => $this->exchange_rate_to_naira !== null ? (string) $this->exchange_rate_to_naira : null,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'pledge_uuid' => $this->pledge_uuid,
            'application_type' => $this->application_type instanceof \BackedEnum ? $this->application_type->value : $this->application_type,
            'superseded_by_transaction_uuid' => $this->superseded_by_transaction_uuid,
            'organization_name' => $this->organization_name,
            'rc_number' => $this->rc_number,
            'tin' => $this->tin,
            'receipt_number' => $this->receipt_number,
            'receipt_download_url' => $receiptLinks['donation'] ?? null,
            'tax_receipt_download_url' => $receiptLinks['tax'] ?? null,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array{donation?: string, tax?: string}
     */
    private function resolveReceiptLinks(): array
    {
        if ($this->status !== \App\Enums\TransactionStatus::SUCCESSFUL) {
            return [];
        }

        if ($this->receipt_number === null || $this->receipt_number === '') {
            return [];
        }

        $receiptService = app(ReceiptService::class);
        $base = rtrim((string) config('app.url'), '/').'/api/v1/receipts/'.$this->receipt_number;

        $links = [
            'donation' => $base.'/download',
        ];

        if ($receiptService->isEligibleForTaxReceipt($this->resource)) {
            $links['tax'] = rtrim((string) config('app.url'), '/')
                .'/api/v1/public/receipts/'.$this->receipt_number.'/tax/download';
        }

        return $links;
    }

    private function resolveDonorName(): ?string
    {
        if ((bool) $this->is_anonymous) {
            return 'Anonymous';
        }

        if ($this->donor !== null) {
            $name = trim(implode(' ', array_filter([
                (string) ($this->donor->firstname ?? ''),
                (string) ($this->donor->lastname ?? ''),
            ])));
            if ($name !== '') {
                return $name;
            }
        }

        return $this->donor_name;
    }
}
