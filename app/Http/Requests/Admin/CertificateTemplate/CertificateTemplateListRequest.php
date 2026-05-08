<?php

namespace App\Http\Requests\Admin\CertificateTemplate;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class CertificateTemplateListRequest extends ApiFormRequest
{
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
}
