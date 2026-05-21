<?php

namespace App\Http\Requests\Customer\Settings;

use App\Http\Requests\ApiFormRequest;

class ToggleTwoFactorRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [];
    }
}
