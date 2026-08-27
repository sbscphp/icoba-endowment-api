<?php

namespace App\Http\Requests\Admin\ContentManagement;

use App\Http\Requests\ApiFormRequest;
use App\Services\Admin\ContentManagement\AdService;

class SyncAdImagesRequest extends ApiFormRequest
{
    /**
     * `images` is the full desired gallery: each entry is either an existing Cloudinary URL
     * to keep, or a new base64/file upload. An ad must always keep at least one image.
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.AdService::MAX_IMAGES],
            'images.*' => ['required'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'images.required' => 'The images field must be provided.',
            'images.array' => 'Images must be provided as a list.',
            'images.min' => 'An ad must have at least one image.',
            'images.max' => 'You may have at most '.AdService::MAX_IMAGES.' images per ad.',
        ]);
    }
}
