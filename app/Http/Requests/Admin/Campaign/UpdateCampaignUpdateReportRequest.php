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
}
