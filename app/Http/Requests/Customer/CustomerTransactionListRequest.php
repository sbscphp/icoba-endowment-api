<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CustomerTransactionListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $filters = $this->input('filters', []);
        if (! is_array($filters)) {
            return;
        }
        if (array_key_exists('scope', $filters) && is_string($filters['scope'])) {
            $filters['scope'] = strtolower(trim($filters['scope']));
            $this->merge(['filters' => $filters]);
        }
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
            'filters' => ['sometimes', 'array'],
            'filters.scope' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'pledges', 'donations'])],
            'filters.user_uuid' => ['prohibited'],
            'user_uuid' => ['prohibited'],
        ];
    }
}
