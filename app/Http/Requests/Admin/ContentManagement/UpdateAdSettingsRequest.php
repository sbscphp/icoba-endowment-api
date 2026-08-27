<?php

namespace App\Http\Requests\Admin\ContentManagement;

use App\Http\Requests\ApiFormRequest;
use App\Services\Admin\ContentManagement\AdService;

class UpdateAdSettingsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'ads_transition_seconds' => ['required', 'integer', 'min:1', 'max:'.AdService::MAX_INTERVAL_SECONDS],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'ads_transition_seconds.required' => 'Seconds between ads is required.',
            'ads_transition_seconds.integer' => 'Seconds between ads must be a whole number.',
            'ads_transition_seconds.min' => 'Seconds between ads must be at least 1 second.',
            'ads_transition_seconds.max' => 'Seconds between ads may not exceed '.AdService::MAX_INTERVAL_SECONDS.' seconds.',
        ]);
    }
}
