<?php

namespace App\Http\Requests\Admin\Media;

use App\Http\Requests\ApiFormRequest;

class UploadImageRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required_without:image', 'file', 'image', 'max:10240'],
            'image' => ['required_without:file', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'file' => 'file',
            'image' => 'image',
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'file.required_without' => 'Please upload an image file or provide an image data string.',
            'file.file' => 'The uploaded value is not a valid file.',
            'file.image' => 'The uploaded file must be an image.',
            'file.max' => 'Image file may not be larger than 10MB.',
            'image.required_without' => 'Please upload an image file or provide an image data string.',
            'image.string' => 'Image data must be a valid string.',
        ]);
    }
}
