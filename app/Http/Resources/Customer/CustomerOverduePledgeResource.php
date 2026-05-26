<?php

namespace App\Http\Resources\Customer;

use App\Models\Pledge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{pledge: Pledge, due_installment: array<string, mixed>} $resource
 */
class CustomerOverduePledgeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Pledge $pledge */
        $pledge = $this->resource['pledge'];
        $installment = $this->resource['due_installment'];

        return [
            'pledge_uuid' => $pledge->uuid,
            'campaign' => $pledge->campaign !== null ? [
                'uuid' => $pledge->campaign->uuid,
                'name' => $pledge->campaign->name,
            ] : null,
            'currency' => $pledge->currency,
            'due_installment' => [
                'id' => $installment['id'],
                'sequence' => (int) $installment['sequence'],
                'due_date' => $installment['due_date'] ?? null,
                'pledged_amount' => $installment['pledged_amount'],
                'pledged_amount_ngn' => $installment['pledged_amount_ngn'],
                'paid_amount' => $installment['paid_amount'],
                'paid_amount_ngn' => $installment['paid_amount_ngn'],
                'remaining_amount' => $installment['remaining_amount'],
                'remaining_amount_ngn' => $installment['remaining_amount_ngn'],
                'currency' => $installment['currency'],
                'status' => $installment['status'],
            ],
        ];
    }
}
