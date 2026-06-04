<?php

namespace App\Http\Requests\Admin\ContactSubmission;

use App\Enums\ContactSubmissionStatus;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateContactSubmissionStatusRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ContactSubmissionStatus::values())],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'status.required' => 'Please select a status.',
            'status.in' => 'Selected status is invalid.',
        ]);
    }
}
