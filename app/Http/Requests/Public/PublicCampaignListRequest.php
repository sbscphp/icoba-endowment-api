<?php

namespace App\Http\Requests\Public;

use App\Enums\PublicCampaignVisibilityFilter;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PublicCampaignListRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', 'string', Rule::in(PublicCampaignVisibilityFilter::values())],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('filter') || $this->input('filter') === null || $this->input('filter') === '') {
            $this->merge(['filter' => PublicCampaignVisibilityFilter::ALL->value]);
        }
    }
}
