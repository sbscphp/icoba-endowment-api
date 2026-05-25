<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class CustomerRecognitionListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);

        if ($this->has('download')) {
            $this->merge([
                'download' => filter_var($this->input('download'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            ]);
        }
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::periodDateRules(),
            [
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'download' => ['sometimes', 'boolean'],
                'recognition_uuid' => [
                    Rule::requiredIf(fn (): bool => $this->boolean('download')),
                    'nullable',
                    'uuid',
                    Rule::exists('donor_recognitions', 'uuid'),
                ],
            ]
        );
    }
}
