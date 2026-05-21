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
            'tier_badge_url' => ['sometimes', 'nullable'],
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
}
