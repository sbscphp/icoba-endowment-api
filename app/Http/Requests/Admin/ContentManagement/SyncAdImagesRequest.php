<?php

namespace App\Http\Requests\Admin\ContentManagement;

use App\Http\Requests\Admin\ContentManagement\Concerns\AdImageSetRules;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;

class SyncAdImagesRequest extends ApiFormRequest
{
    use AdImageSetRules;

    /**
     * Send `landscape_images` and/or `portrait_images`; each is the full desired gallery for
     * that orientation (existing Cloudinary URLs to keep + new base64/file uploads). Only the
     * orientations sent are reconciled. The ad must always keep at least one image overall.
     */
    public function rules(): array
    {
        return $this->imageSetRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasAnyImageSet()) {
                $validator->errors()->add('landscape_images', 'Provide landscape_images and/or portrait_images to update.');
            }
        });
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), $this->imageSetMessages());
    }
}
