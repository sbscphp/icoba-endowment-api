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
}
