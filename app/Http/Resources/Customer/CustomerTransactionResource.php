<?php

namespace App\Http\Resources\Customer;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class CustomerTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Transaction $tx */
        $tx = $this->resource;

        $row = [
            'transaction_uuid' => $tx->uuid,
            'transaction_id' => $tx->transaction_id,
            'amount' => (string) $tx->amount,
            'currency' => $tx->currency,
            'amount_in_naira' => $tx->amount_in_naira !== null ? (string) $tx->amount_in_naira : null,
            'status' => $tx->status->value,
            'paid_at' => $tx->paid_at,
            'is_anonymous' => (bool) $tx->is_anonymous,
            'application_type' => $tx->application_type?->value,
            'pledge_uuid' => $tx->pledge_uuid,
            'linked_campaign' => $tx->campaign !== null ? [
                'name' => $tx->campaign->name,
            ] : null,
        ];

        if (! $tx->is_anonymous) {
            $row['donor_name'] = $tx->donor_name;
            $row['donor_email'] = $tx->donor_email;
            $row['donor_phone'] = $tx->donor_phone;
        } else {
            $row['display_name'] = 'Anonymous';
        }

        return $row;
    }
}
