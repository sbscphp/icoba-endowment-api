<?php

namespace App\Http\Resources\Admin;

use App\Models\PaymentFeeRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentFeeRule
 */
class PaymentFeeRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'rule_id' => $this->uuid,
            'name' => $this->name,
            'currency' => $this->currency,
            'min_amount' => (string) $this->min_amount,
            'max_amount' => $this->max_amount !== null ? (string) $this->max_amount : null,
            'percentage' => (string) $this->percentage,
            'fixed_amount' => (string) $this->fixed_amount,
            'cap_amount' => $this->cap_amount !== null ? (string) $this->cap_amount : null,
            'cap_currency' => $this->cap_amount !== null ? $this->capCurrency() : null,
            'is_active' => (bool) $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'transactions_count' => (int) ($this->transactions_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
