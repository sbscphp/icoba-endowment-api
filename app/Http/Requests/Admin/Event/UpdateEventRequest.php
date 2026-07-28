<?php

namespace App\Http\Requests\Admin\Event;

use App\Enums\EventStatus;
use App\Http\Requests\ApiFormRequest;
use App\Services\Admin\Event\EventService;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends ApiFormRequest
{
    /**
     * `images`, when present, is the full desired gallery (existing URLs to keep + new
     * base64/file entries to upload) — see SyncEventImagesRequest. Send an empty array
     * to clear the gallery; omit the field entirely to leave the gallery untouched.
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'short_description' => ['sometimes', 'string', 'max:500'],
            'long_description' => ['sometimes', 'string'],
            'event_date' => ['sometimes', 'date'],
            'banner' => ['sometimes'],
            'status' => ['sometimes', 'nullable', Rule::in(EventStatus::values())],
            'images' => ['sometimes', 'array', 'max:'.EventService::MAX_GALLERY_IMAGES],
            'images.*' => ['required'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'title.max' => 'Event title may not be longer than 255 characters.',
            'short_description.max' => 'Short description may not be longer than 500 characters.',
            'event_date.date' => 'Event date must be a valid date.',
            'status.in' => 'Event status is invalid.',
            'images.array' => 'Images must be provided as a list.',
            'images.max' => 'You may have at most '.EventService::MAX_GALLERY_IMAGES.' images.',
        ]);
    }
}
