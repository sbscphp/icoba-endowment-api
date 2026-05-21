<?php

namespace App\Http\Requests\Public;

use App\Enums\PublicCampaignVisibilityFilter;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PublicCampaignDropdownRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'string', Rule::in(PublicCampaignVisibilityFilter::values())],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('filter') || $this->input('filter') === null || $this->input('filter') === '') {
            $this->merge(['filter' => PublicCampaignVisibilityFilter::ALL->value]);
        }
    }
}
