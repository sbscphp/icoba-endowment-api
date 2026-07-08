<?php

namespace App\Http\Requests\Customer\Notification;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class NotificationListRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
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
        ]);
    }
}
