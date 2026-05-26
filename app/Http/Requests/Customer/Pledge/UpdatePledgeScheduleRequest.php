<?php

namespace App\Http\Requests\Customer\Pledge;

use App\Enums\PledgePaymentPreference;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdatePledgeScheduleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['set_preference'])],
            'payment_preference' => [
                'required_if:action,set_preference',
                'nullable',
                'string',
                Rule::in(PledgePaymentPreference::values()),
            ],
        ];
    }
}
