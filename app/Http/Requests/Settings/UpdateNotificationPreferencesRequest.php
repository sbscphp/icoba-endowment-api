<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\ApiFormRequest;

class UpdateNotificationPreferencesRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['email_notifications_enabled', 'push_notifications_enabled'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (is_bool($value)) {
                $normalized[$field] = $value;
                continue;
            }

            if (is_string($value)) {
                $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) {
                    $normalized[$field] = $parsed;
                }
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'email_notifications_enabled' => ['required_without:push_notifications_enabled', 'boolean'],
            'push_notifications_enabled' => ['required_without:email_notifications_enabled', 'boolean'],
        ];
    }
}

