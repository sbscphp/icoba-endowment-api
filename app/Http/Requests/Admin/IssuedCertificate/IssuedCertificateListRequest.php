<?php

namespace App\Http\Requests\Admin\IssuedCertificate;

use App\Enums\IssuedCertificateStatus;
use App\Enums\PaymentGateway;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class IssuedCertificateListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules([
                'recognition_number',
                'awardee_name',
                'issued_at',
                'cumulative_amount_ngn',
                'status',
                'tier_name',
                'paid_into',
                'created_at',
                'updated_at',
            ]),
            [
                'export' => ['sometimes', 'nullable', Rule::in(['csv', 'pdf'])],
                'filters.status' => ['sometimes', 'nullable', Rule::in(IssuedCertificateStatus::values())],
                'filters.tier_uuid' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::exists('tier_configurations', 'uuid'),
                ],
                'filters.gateway' => ['sometimes', 'nullable', Rule::in(PaymentGateway::values())],
            ]
        );
    }
}
