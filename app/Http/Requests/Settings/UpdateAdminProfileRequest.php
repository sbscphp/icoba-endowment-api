<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\ApiFormRequest;

class UpdateAdminProfileRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Please enter your name.',
        ]);
    }
}
