<?php

namespace App\Http\Requests\Admin\ContentManagement;

use App\Http\Requests\ApiFormRequest;

class UpdateFaqRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'string', 'max:10000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'title.max' => 'FAQ title may not be longer than 255 characters.',
            'content.max' => 'FAQ content may not be longer than 10000 characters.',
            'sort_order.integer' => 'Sort order must be a whole number.',
            'sort_order.min' => 'Sort order must be at least 0.',
        ]);
    }
}
