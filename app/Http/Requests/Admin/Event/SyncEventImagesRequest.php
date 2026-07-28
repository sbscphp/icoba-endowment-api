<?php

namespace App\Http\Requests\Admin\Event;

use App\Http\Requests\ApiFormRequest;
use App\Services\Admin\Event\EventService;

class SyncEventImagesRequest extends ApiFormRequest
{
    /**
     * `images` is the full desired gallery: each entry is either an existing Cloudinary URL
     * to keep, or a new base64/file upload. Send an empty array to clear the gallery.
     */
    public function rules(): array
    {
        return [
            'images' => ['present', 'array', 'max:'.EventService::MAX_GALLERY_IMAGES],
            'images.*' => ['required'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'images.present' => 'The images field must be provided (send an empty array to clear the gallery).',
            'images.array' => 'Images must be provided as a list.',
            'images.max' => 'You may have at most '.EventService::MAX_GALLERY_IMAGES.' images.',
        ]);
    }
}
