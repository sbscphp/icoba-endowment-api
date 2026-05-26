<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicBankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'bank_name' => $this->resource['bank_name'],
            'account_name' => $this->resource['account_name'],
            'accounts' => collect($this->resource['accounts'])
                ->map(fn (array $account): array => [
                    'account_key' => $account['account_key'],
                    'currency' => $account['currency'],
                    'currency_symbol' => $account['currency_symbol'],
                    'account_number' => $account['account_number'],
                ])
                ->values()
                ->all(),
        ];
    }
}
