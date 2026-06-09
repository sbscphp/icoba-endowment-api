<?php

namespace App\Http\Requests\Customer\Notification;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class NotificationListRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filters' => ['sometimes', 'array'],
            'filters.read_status' => ['sometimes', 'nullable', Rule::in(['all', 'read', 'unread'])],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'filters.read_status.in' => 'Read status filter must be one of: all, read, unread.',
            'page.integer' => 'Page must be a number.',
            'page.min' => 'Page must be at least 1.',
            'per_page.integer' => 'Per page must be a number.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page may not be greater than 100.',
        ]);
    }
}
