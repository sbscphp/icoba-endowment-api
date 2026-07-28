<?php

namespace App\Http\Requests\Admin\Event;

use App\Enums\EventStatus;
use App\Http\Requests\ApiFormRequest;
use App\Services\Admin\Event\EventService;
use Illuminate\Validation\Rule;

class CreateEventRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['required', 'string', 'max:500'],
            'long_description' => ['required', 'string'],
            'event_date' => ['required', 'date'],
            'banner' => ['required'],
            'status' => ['sometimes', 'nullable', Rule::in(EventStatus::values())],
            'images' => ['sometimes', 'nullable', 'array', 'max:'.EventService::MAX_GALLERY_IMAGES],
            'images.*' => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'title.required' => 'Event title is required.',
            'title.max' => 'Event title may not be longer than 255 characters.',
            'short_description.required' => 'Short description is required.',
            'short_description.max' => 'Short description may not be longer than 500 characters.',
            'long_description.required' => 'Long description is required.',
            'event_date.required' => 'Event date is required.',
            'event_date.date' => 'Event date must be a valid date.',
            'banner.required' => 'Event banner is required.',
            'status.in' => 'Event status is invalid.',
            'images.array' => 'Images must be provided as a list.',
            'images.max' => 'You may upload at most '.EventService::MAX_GALLERY_IMAGES.' images.',
        ]);
    }
}
