<?php

namespace App\Http\Requests\Admin\Tier;

use App\Enums\TierBenefit;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CreateTierConfigurationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tier_configurations', 'name'),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('tier_configurations', 'slug'),
            ],
            'tier_badge_url' => ['sometimes', 'nullable'],
            'base_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'min_amount' => ['required', 'numeric', 'min:0'],
            'max_amount' => ['sometimes', 'nullable', 'numeric', 'gt:min_amount'],
            'benefits' => ['sometimes', 'nullable', 'array', 'max:20'],
            'benefits.*' => ['string', Rule::in(TierBenefit::values())],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
