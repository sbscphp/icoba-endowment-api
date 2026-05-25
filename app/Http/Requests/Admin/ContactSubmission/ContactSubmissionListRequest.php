<?php

namespace App\Http\Requests\Admin\ContactSubmission;

use App\Enums\ContactSubmissionStatus;
use App\Enums\ContactSubmissionUserType;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class ContactSubmissionListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules(['full_name', 'email', 'user_type', 'status', 'created_at', 'updated_at']),
            [
                'filters.status' => ['sometimes', 'nullable', Rule::in(ContactSubmissionStatus::values())],
                'filters.user_type' => ['sometimes', 'nullable', Rule::enum(ContactSubmissionUserType::class)],
            ]
        );
    }
}
