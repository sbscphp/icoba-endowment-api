<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'transaction_uuid' => $this->uuid,
            'transaction_id' => $this->transaction_id,
            'donor_name' => $this->resolveDonorName(),
            'donor_email' => $this->donor_email ?? $this->donor?->email,
            'is_anonymous' => (bool) $this->is_anonymous,
            'linked_campaign' => $this->campaign !== null ? [
                'campaign_id' => $this->campaign->uuid,
                'public_campaign_code' => $this->campaign->campaign_id,
                'name' => $this->campaign->name,
            ] : null,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'amount_in_naira' => $this->amount_in_naira !== null ? (string) $this->amount_in_naira : null,
            'gateway' => $this->gateway,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
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
