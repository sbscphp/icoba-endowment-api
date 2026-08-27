<?php

namespace App\Http\Requests\Admin\ContentManagement;

use App\Http\Requests\ApiFormRequest;
use App\Services\Admin\ContentManagement\AdService;
use Illuminate\Validation\Rule;

class CreateAdRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'target_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'image_interval_seconds' => ['sometimes', 'integer', 'min:1', 'max:'.AdService::MAX_INTERVAL_SECONDS],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'nullable', Rule::in(['live', 'scheduled', 'expired', 'archived'])],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'images' => ['required', 'array', 'min:1', 'max:'.AdService::MAX_IMAGES],
            'images.*' => ['required'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'title.required' => 'Ad title is required.',
            'title.max' => 'Ad title may not be longer than 255 characters.',
            'target_url.url' => 'Target URL must be a valid URL.',
            'target_url.max' => 'Target URL may not be longer than 2048 characters.',
            'image_interval_seconds.integer' => 'Seconds between images must be a whole number.',
            'image_interval_seconds.min' => 'Seconds between images must be at least 1 second.',
            'image_interval_seconds.max' => 'Seconds between images may not exceed '.AdService::MAX_INTERVAL_SECONDS.' seconds.',
            'starts_at.required' => 'Ad start date and time is required.',
            'starts_at.date' => 'Ad start date and time must be a valid date.',
            'ends_at.required' => 'Ad end date and time is required.',
            'ends_at.date' => 'Ad end date and time must be a valid date.',
            'ends_at.after' => 'Ad end date and time must be after the start date and time.',
            'is_active.boolean' => 'Active status must be true or false.',
            'status.in' => 'Ad status is invalid.',
            'sort_order.integer' => 'Sort order must be a whole number.',
            'images.required' => 'At least one ad image is required.',
            'images.array' => 'Images must be provided as a list.',
            'images.min' => 'At least one ad image is required.',
            'images.max' => 'You may upload at most '.AdService::MAX_IMAGES.' images per ad.',
        ]);
    }
}
