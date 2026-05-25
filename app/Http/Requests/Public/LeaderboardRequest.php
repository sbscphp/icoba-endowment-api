<?php

namespace App\Http\Requests\Public;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class LeaderboardRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'mode' => ['sometimes', 'string', Rule::in(['all', 'donor_type', 'set'])],
            'campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
            'donor_type_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:donor_types,uuid'],
            'graduation_set_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:sets,uuid'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'currency' => ['sometimes', 'string', Rule::in(Currency::values())],
            'scope' => ['sometimes', 'string', Rule::in(['all', 'donations', 'pledges'])],
            'sort_by' => ['sometimes', 'string', Rule::in(['name', 'amount', 'set'])],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
