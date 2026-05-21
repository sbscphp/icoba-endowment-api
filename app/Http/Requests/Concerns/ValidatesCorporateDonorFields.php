<?php

namespace App\Http\Requests\Concerns;

use App\Enums\DonorTypeSlug;
use App\Models\DonorType;
use Illuminate\Validation\Rule;

trait ValidatesCorporateDonorFields
{
    /**
     * @return array<string, list<string|\Illuminate\Contracts\Validation\ValidationRule>>
     */
    protected function corporateDonorFieldRules(bool $requiredWhenCorporate = true): array
    {
        $required = $requiredWhenCorporate
            ? [Rule::requiredIf(fn () => $this->isCorporateDonorTypeSelected())]
            : ['sometimes', 'nullable'];

        return [
            'organization_name' => array_merge($required, ['string', 'min:2', 'max:150']),
            'rc_number' => array_merge($required, ['string', 'min:2', 'max:64']),
            'tin' => array_merge($required, ['string', 'min:2', 'max:64']),
        ];
    }

    protected function isCorporateDonorTypeSelected(): bool
    {
        $slug = $this->corporateDonorTypeSlugFromRequest();

        return $slug === DonorTypeSlug::CORPORATE_DONOR->value;
    }

    protected function corporateDonorTypeSlugFromRequest(): ?string
    {
        $donorType = strtolower(trim((string) $this->input('donor_type', '')));
        if ($donorType !== '') {
            return $donorType;
        }

        $donorTypeUuid = $this->input('donor_type_uuid');
        if (! is_string($donorTypeUuid) || $donorTypeUuid === '') {
            return null;
        }

        return DonorType::query()->where('uuid', $donorTypeUuid)->value('slug');
    }
}
