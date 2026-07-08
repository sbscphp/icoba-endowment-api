<?php

namespace App\Http\Requests\Customer\Settings;

use App\Http\Requests\ApiFormRequest;

class ToggleTwoFactorRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
