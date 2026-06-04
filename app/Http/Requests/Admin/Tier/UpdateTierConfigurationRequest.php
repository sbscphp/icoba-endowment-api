<?php

namespace App\Http\Requests\Admin\Tier;

use App\Enums\TierBenefit;
use App\Http\Requests\ApiFormRequest;
use App\Models\TierConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class UpdateTierConfigurationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $tierId = (string) $this->route('tierId');
        $tier = TierConfiguration::query()
            ->where(function (Builder $query) use ($tierId): void {
                $query->where('uuid', $tierId);
                if (is_numeric($tierId)) {
                    $query->orWhere('id', (int) $tierId);
                }
            })
            ->first();

        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('tier_configurations', 'name')->ignore($tier?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('tier_configurations', 'slug')->ignore($tier?->id),
            ],
            'tier_badge_url' => ['sometimes', 'nullable'],
            'base_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'min_amount' => ['sometimes', 'numeric', 'min:0'],
            'max_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                function (string $attribute, mixed $value, \Closure $fail) use ($tier): void {
                    if ($value === null) {
                        return;
                    }

                    $incomingMin = $this->input('min_amount');
                    $effectiveMin = $incomingMin !== null ? (float) $incomingMin : ($tier?->min_amount !== null ? (float) $tier->min_amount : null);
                    if ($effectiveMin !== null && (float) $value <= $effectiveMin) {
                        $fail('The max amount field must be greater than min amount.');
                    }
                },
            ],
            'benefits' => ['sometimes', 'nullable', 'array', 'max:20'],
            'benefits.*' => ['string', Rule::in(TierBenefit::values())],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.unique' => 'Another tier is already using this name.',
            'name.max' => 'Tier name may not be longer than 100 characters.',
            'description.max' => 'Description may not be longer than 255 characters.',
            'slug.max' => 'Slug may not be longer than 120 characters.',
            'slug.regex' => 'Slug must be lowercase letters, numbers, and hyphens (e.g. "gold-tier").',
            'slug.unique' => 'Another tier is already using this slug.',
            'base_color.regex' => 'Base color must be a hex color (e.g. "#fff" or "#ffffff").',
            'min_amount.numeric' => 'Minimum amount must be a number.',
            'min_amount.min' => 'Minimum amount must be at least 0.',
            'max_amount.numeric' => 'Maximum amount must be a number.',
            'benefits.array' => 'Benefits must be provided as a list.',
            'benefits.max' => 'You may define at most 20 benefits.',
            'benefits.*.in' => 'One or more selected benefits are invalid.',
            'sort_order.integer' => 'Sort order must be a whole number.',
            'sort_order.min' => 'Sort order must be at least 0.',
        ]);
    }
}
