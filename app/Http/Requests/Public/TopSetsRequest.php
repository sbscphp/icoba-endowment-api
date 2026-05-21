<?php

namespace App\Http\Requests\Public;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class TopSetsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
            'currency' => ['sometimes', 'string', Rule::in(Currency::values())],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }
}
