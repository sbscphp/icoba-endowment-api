<?php

namespace App\Http\Requests\Admin\Campaign;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CampaignLifecycleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['activate', 'pause', 'resume', 'deactivate'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'action.required' => 'Please select a lifecycle action to perform.',
            'action.in' => 'Action must be one of: activate, pause, resume, or deactivate.',
            'reason.max' => 'Reason may not be longer than 500 characters.',
        ]);
    }
}
