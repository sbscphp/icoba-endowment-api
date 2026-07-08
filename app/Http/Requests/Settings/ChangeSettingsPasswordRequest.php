<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\ApiFormRequest;
use App\Support\PasswordRules;

class ChangeSettingsPasswordRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', PasswordRules::make(), 'confirmed'],
        ];
    }
}
