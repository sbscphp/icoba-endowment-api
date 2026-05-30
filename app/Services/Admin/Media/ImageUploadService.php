<?php

namespace App\Services\Admin\Media;

use App\Exceptions\ApiException;
use App\Helpers\FileUploadHelper;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class ImageUploadService
{
    private const UPLOAD_FOLDER = 'uploads/images';

    /** @var list<string> */
    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];

    public function upload(?UploadedFile $file, ?string $base64): string
    {
        if ($file instanceof UploadedFile) {
            return $this->uploadFromFile($file);
        }

        if (! is_string($base64) || trim($base64) === '') {
            throw new ApiException('Provide a file upload or base64 image.', 422);
        }

        return $this->uploadFromBase64(trim($base64));
    }

    private function uploadFromFile(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new ApiException('The uploaded file is invalid.', 422);
        }

        try {
            $url = FileUploadHelper::smartSingleFileUpload($file, self::UPLOAD_FOLDER);
        } catch (InvalidArgumentException $e) {
            throw new ApiException($e->getMessage(), 422);
        }

        if ($url === null) {
            throw new ApiException('Image upload failed.', 422);
        }

        return $url;
    }

    private function uploadFromBase64(string $base64): string
    {
        $base64Data = preg_replace('#^data:[^;]+;base64,#i', '', $base64);
        $decoded = base64_decode((string) $base64Data, true);

        if ($decoded === false) {
            throw new ApiException('Invalid base64 encoding.', 422);
        }

        $this->assertImageContent($decoded);

        try {
            $url = FileUploadHelper::smartSingleFileUpload($base64, self::UPLOAD_FOLDER);
        } catch (InvalidArgumentException $e) {
            throw new ApiException($e->getMessage(), 422);
        }

        if ($url === null) {
            throw new ApiException('Image upload failed.', 422);
        }

        return $url;
    }

    private function assertImageContent(string $binary): void
    {
        if ($binary === '') {
            throw new ApiException('Decoded image data is empty.', 422);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (! $finfo) {
            throw new ApiException('Unable to detect image type.', 422);
        }

        $mime = finfo_buffer($finfo, $binary);
        finfo_close($finfo);

        if (! is_string($mime) || ! in_array($mime, self::IMAGE_MIME_TYPES, true)) {
            throw new ApiException('Only image files are allowed.', 422);
        }
    }
}
