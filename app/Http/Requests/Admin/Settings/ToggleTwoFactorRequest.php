<?php

namespace App\Http\Requests\Admin\Settings;

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
