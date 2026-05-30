<?php

namespace App\Http\Controllers\v1\Admin\Media;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Media\UploadImageRequest;
use App\Responser\JsonResponser;
use App\Services\Admin\Media\ImageUploadService;

class ImageUploadController extends Controller
{
    public function __construct(private readonly ImageUploadService $imageUploadService) {}

    public function store(UploadImageRequest $request)
    {
        try {
            $url = $this->imageUploadService->upload(
                $request->file('file'),
                $request->input('image'),
            );

            return JsonResponser::send(false, 'Image uploaded successfully.', ['url' => $url], 201);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Media\ImageUploadController@store');
        }
    }
}
