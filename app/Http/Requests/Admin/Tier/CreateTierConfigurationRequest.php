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

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.unique' => 'A tier with this name already exists.',
            'name.max' => 'Tier name may not be longer than 100 characters.',
            'description.max' => 'Description may not be longer than 255 characters.',
            'slug.max' => 'Slug may not be longer than 120 characters.',
            'slug.regex' => 'Slug must be lowercase letters, numbers, and hyphens (e.g. "gold-tier").',
            'slug.unique' => 'A tier with this slug already exists.',
            'base_color.regex' => 'Base color must be a hex color (e.g. "#fff" or "#ffffff").',
            'min_amount.required' => 'Please specify a minimum amount for this tier.',
            'min_amount.numeric' => 'Minimum amount must be a number.',
            'min_amount.min' => 'Minimum amount must be at least 0.',
            'max_amount.numeric' => 'Maximum amount must be a number.',
            'max_amount.gt' => 'Maximum amount must be greater than minimum amount.',
            'benefits.array' => 'Benefits must be provided as a list.',
            'benefits.max' => 'You may define at most 20 benefits.',
            'benefits.*.in' => 'One or more selected benefits are invalid.',
            'sort_order.integer' => 'Sort order must be a whole number.',
            'sort_order.min' => 'Sort order must be at least 0.',
        ]);
    }
}
