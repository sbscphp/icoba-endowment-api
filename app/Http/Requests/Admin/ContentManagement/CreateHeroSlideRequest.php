<?php

namespace App\Http\Requests\Admin\ContentManagement;

use App\Http\Requests\ApiFormRequest;

class CreateHeroSlideRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'banner_url' => ['required'],
            'primary_cta_url' => ['required', 'string', 'max:500'],
            'primary_cta_text' => ['required', 'string', 'max:100'],
            'secondary_cta_url' => ['required', 'string', 'max:500'],
            'secondary_cta_text' => ['required', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'title.required' => 'Campaign title is required.',
            'title.max' => 'Campaign title may not be longer than 255 characters.',
            'banner_url.required' => 'Banner image is required.',
            'primary_cta_url.required' => 'Primary CTA link is required.',
            'primary_cta_text.required' => 'Primary CTA text is required.',
            'secondary_cta_url.required' => 'Secondary CTA link is required.',
            'secondary_cta_text.required' => 'Secondary CTA text is required.',
            'sort_order.integer' => 'Sort order must be a whole number.',
            'sort_order.min' => 'Sort order must be at least 0.',
        ]);
    }
}
