<?php

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\ApiFormRequest;

class ToggleTwoFactorRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [];
    }
}
