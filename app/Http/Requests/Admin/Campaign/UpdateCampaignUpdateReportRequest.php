<?php

namespace App\Http\Requests\Admin\Campaign;

use App\Http\Requests\ApiFormRequest;

class UpdateCampaignUpdateReportRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'short_description' => ['sometimes', 'string', 'max:1000'],
            'details' => ['sometimes', 'string'],
            'banner' => ['sometimes', 'nullable'],
            'youtube_link' => ['sometimes', 'nullable', 'url', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.max' => 'Update report name may not be longer than 200 characters.',
            'short_description.max' => 'Short description may not be longer than 1000 characters.',
            'youtube_link.url' => 'YouTube link must be a valid URL.',
            'youtube_link.max' => 'YouTube link may not be longer than 500 characters.',
        ]);
    }
}
