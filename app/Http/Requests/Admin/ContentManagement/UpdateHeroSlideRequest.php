<?php

namespace App\Http\Requests\Admin\ContentManagement;

use App\Http\Requests\ApiFormRequest;

class UpdateHeroSlideRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'banner_url' => ['sometimes'],
            'primary_cta_url' => ['sometimes', 'string', 'max:500'],
            'primary_cta_text' => ['sometimes', 'string', 'max:100'],
            'secondary_cta_url' => ['sometimes', 'string', 'max:500'],
            'secondary_cta_text' => ['sometimes', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'title.max' => 'Campaign title may not be longer than 255 characters.',
            'sort_order.integer' => 'Sort order must be a whole number.',
            'sort_order.min' => 'Sort order must be at least 0.',
        ]);
    }
}
