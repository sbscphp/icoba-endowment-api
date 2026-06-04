<?php

namespace App\Http\Requests\Admin\Campaign;

use App\Http\Requests\ApiFormRequest;

class CreateCampaignUpdateReportRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'short_description' => ['required', 'string', 'max:1000'],
            'details' => ['required', 'string'],
            'banner' => ['required'],
            'youtube_link' => ['sometimes', 'nullable', 'url', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.max' => 'Update report name may not be longer than 200 characters.',
            'short_description.max' => 'Short description may not be longer than 1000 characters.',
            'details.required' => 'Please provide the update report details.',
            'banner.required' => 'Please upload a banner for the update report.',
            'youtube_link.url' => 'YouTube link must be a valid URL.',
            'youtube_link.max' => 'YouTube link may not be longer than 500 characters.',
        ]);
    }
}
