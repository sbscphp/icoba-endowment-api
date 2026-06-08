<?php

namespace App\Http\Requests\Customer\Pledge;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdatePledgePauseRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['pause', 'resume'])],
            'resume_date' => ['required_if:action,pause', 'date', 'after:today'],
        ];
    }
}
