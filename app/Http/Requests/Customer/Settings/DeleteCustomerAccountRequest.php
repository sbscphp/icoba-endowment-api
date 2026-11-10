<?php

namespace App\Http\Requests\Customer\Settings;

use App\Http\Requests\ApiFormRequest;

class DeleteCustomerAccountRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'reason.max' => 'The reason may not be longer than 500 characters.',
        ]);
    }
}
