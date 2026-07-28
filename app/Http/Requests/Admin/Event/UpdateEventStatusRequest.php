<?php

namespace App\Http\Requests\Admin\Event;

use App\Enums\EventStatus;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateEventStatusRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(EventStatus::values())],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'status.required' => 'Please specify the event status.',
            'status.in' => 'Event status is invalid.',
        ]);
    }
}
