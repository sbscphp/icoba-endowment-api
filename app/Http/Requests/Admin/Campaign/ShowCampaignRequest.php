<?php

namespace App\Http\Requests\Admin\Campaign;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ShowCampaignRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'filters.raised_currency' => ['sometimes', 'nullable', Rule::in(Currency::values())],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'filters.raised_currency.in' => 'Raised currency filter is invalid.',
        ]);
    }
}
