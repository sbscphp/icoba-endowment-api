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
}
