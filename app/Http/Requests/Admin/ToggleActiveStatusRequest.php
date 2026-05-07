<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ToggleActiveStatusRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
