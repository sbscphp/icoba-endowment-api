<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CustomerTransactionListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('filter') && is_string($this->input('filter'))) {
            $this->merge(['filter' => strtolower(trim($this->input('filter')))]);
        }
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
            'filter' => ['sometimes', 'nullable', 'string', Rule::in(['', 'all', 'pledges', 'donations'])],
            'user_uuid' => ['prohibited'],
        ];
    }
}
