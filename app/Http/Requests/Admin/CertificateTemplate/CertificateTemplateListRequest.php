<?php

namespace App\Http\Requests\Admin\CertificateTemplate;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class CertificateTemplateListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules(['name', 'is_active', 'created_at', 'updated_at']),
            [
                'filters.status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
                'filters.tier_id' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::exists('tier_configurations', 'uuid'),
                ],
            ]
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::listingMessages(), [
            'filters.status.in' => "Status filter must be either 'active' or 'inactive'.",
            'filters.tier_id.exists' => 'Selected tier does not exist.',
        ]);
    }
}
