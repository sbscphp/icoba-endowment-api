<?php

namespace App\Http\Requests\Admin\Event;

use App\Enums\EventStatus;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class EventListRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules(['title', 'event_date', 'status', 'created_at', 'updated_at']),
            [
                'filters.status' => ['sometimes', 'nullable', Rule::in(EventStatus::values())],
            ]
        );
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ListingFilterRules::listingMessages(), [
            'filters.status.in' => 'Event status filter is invalid.',
        ]);
    }
}
