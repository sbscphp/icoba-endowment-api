<?php

namespace App\Http\Requests\Customer\Settings;

use App\Http\Requests\ApiFormRequest;

class ToggleBiometricsRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('biometrics_enabled')) {
            return;
        }

        $value = $this->input('biometrics_enabled');

        if (is_bool($value)) {
            return;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                $this->merge(['biometrics_enabled' => $parsed]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'biometrics_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
