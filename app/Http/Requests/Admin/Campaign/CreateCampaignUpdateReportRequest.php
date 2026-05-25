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
}
