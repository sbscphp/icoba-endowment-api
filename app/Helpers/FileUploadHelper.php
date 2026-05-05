<?php

namespace App\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\File;

class FileUploadHelper
{
    /**
     * Allowed MIME types for uploads (images, PDF, documents)
     */
    private static array $allowedMimeTypes = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
    ];

    private static int $maxFileSize = 10 * 1024 * 1024;

    /**
     * Multipart file, existing HTTP(S) URL, or base64 (optionally data-URI). Primary entry for uploads.
     */
    public static function smartSingleFileUpload(mixed $fileInput, string $fileKey): ?string
    {
        if ($fileInput === null || $fileInput === '') {
            return null;
        }

        if ($fileInput instanceof UploadedFile) {
            return $fileInput->isValid() ? self::uploadBinaryFile($fileInput, $fileKey) : null;
        }

        if (! is_string($fileInput)) {
            return null;
        }

        $trimmed = trim($fileInput);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            $parsed = parse_url($trimmed);
            if ($parsed && isset($parsed['scheme'], $parsed['host'])) {
                return $trimmed;
            }
        }

        return self::uploadBase64String($trimmed, $fileKey);
    }

    /**
     * @param  array<int, mixed>|string|null  $fileInputs  List of inputs accepted by {@see smartSingleFileUpload}, or pipe/comma-separated string.
     * @return list<string>
     */
    public static function smartMultipleFileUpload(array|string|null $fileInputs, string $fileKey): array
    {
        if ($fileInputs === null || $fileInputs === '') {
            return [];
        }

        if (is_string($fileInputs)) {
            $fileInputs = str_contains($fileInputs, '|')
                ? explode('|', $fileInputs)
                : explode(',', $fileInputs);
        }

        if (! is_array($fileInputs)) {
            return [];
        }

        $urls = [];
        foreach ($fileInputs as $fileInput) {
            if (is_string($fileInput)) {
                $fileInput = trim($fileInput);
            }
            if ($fileInput === null || $fileInput === '') {
                continue;
            }

            try {
                $uploaded = self::smartSingleFileUpload($fileInput, $fileKey);
                if ($uploaded !== null && ! in_array($uploaded, $urls, true)) {
                    $urls[] = $uploaded;
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to upload file in batch', [
                    'error' => $e->getMessage(),
                    'file_key' => $fileKey,
                ]);
            }
        }

        return $urls;
    }

    private static function uploadBinaryFile(UploadedFile $file, string $fileKey): string
    {
        $uniqueId = random_int(10, 100_000);
        $baseName = $uniqueId.'_'.date('Y-m-d').'_'.time();

        return self::storeUploadedFileOnCloudinary($file, $fileKey, $baseName);
    }

    private static function storeUploadedFileOnCloudinary(UploadedFile $file, string $directory, string $baseName, ?string $extension = null): string
    {
        $ext = $extension ?? $file->getClientOriginalExtension();
        $ext = $ext !== '' ? strtolower(ltrim($ext, '.')) : '';
        $filename = $ext !== '' ? $baseName.'.'.$ext : $baseName;

        $path = $file->storeAs($directory, $filename, 'cloudinary');

        if ($path === false) {
            throw new InvalidArgumentException('Cloudinary upload failed.');
        }

        return Storage::disk('cloudinary')->url($path);
    }

    private static function uploadBase64String(string $requestFile, string $fileKey): string
    {
        $tmpFilePath = null;

        try {
            $base64Data = preg_replace('#^data:[^;]+;base64,#i', '', $requestFile);
            $fileData = base64_decode($base64Data, true);

            if ($fileData === false) {
                throw new InvalidArgumentException('Invalid base64 encoding.');
            }

            $validation = self::validateDecodedContent($fileData, $requestFile);
            $detectedMimeType = $validation['mime'];
            $extension = $validation['extension'];

            $uniqueId = random_int(10, 100_000);
            $publicBaseName = $uniqueId.'_'.date('Y-m-d').'_'.time();
            $tmpFilePath = sys_get_temp_dir().'/'.$publicBaseName.'.'.$extension;

            if (file_put_contents($tmpFilePath, $fileData) === false) {
                throw new InvalidArgumentException('Failed to write temporary file.');
            }

            if (! file_exists($tmpFilePath) || filesize($tmpFilePath) !== strlen($fileData)) {
                throw new InvalidArgumentException('File write verification failed.');
            }

            $tmpFile = new File($tmpFilePath);
            $fileMimeType = $tmpFile->getMimeType();

            if ($fileMimeType !== $detectedMimeType && ! in_array($fileMimeType, self::$allowedMimeTypes, true)) {
                throw new InvalidArgumentException("File MIME type mismatch. Detected: {$detectedMimeType}, File reports: {$fileMimeType}");
            }

            $file = new UploadedFile(
                $tmpFile->getPathname(),
                $tmpFile->getFilename(),
                $detectedMimeType,
                null,
                true,
            );

            return self::storeUploadedFileOnCloudinary($file, $fileKey, $publicBaseName, $extension);
        } catch (\Exception $e) {
            Log::error('File upload validation failed', [
                'error' => $e->getMessage(),
                'file_key' => $fileKey,
            ]);
            throw new InvalidArgumentException('File upload failed: '.$e->getMessage());
        } finally {
            if ($tmpFilePath && file_exists($tmpFilePath)) {
                @unlink($tmpFilePath);
            }
        }
    }

    /**
     * @return array{mime: string, extension: string}
     */
    private static function validateDecodedContent(string $fileData, string $originalInput = ''): array
    {
        $fileSize = strlen($fileData);
        if ($fileSize === 0) {
            throw new InvalidArgumentException('Decoded file data is empty.');
        }

        if ($fileSize > self::$maxFileSize) {
            $maxSizeMB = self::$maxFileSize / (1024 * 1024);
            throw new InvalidArgumentException("File size exceeds maximum allowed size of {$maxSizeMB}MB.");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (! $finfo) {
            throw new InvalidArgumentException('Unable to detect file type.');
        }

        $detectedMimeType = finfo_buffer($finfo, $fileData);
        finfo_close($finfo);

        if (! $detectedMimeType || ! in_array($detectedMimeType, self::$allowedMimeTypes, true)) {
            throw new InvalidArgumentException(
                "File type '{$detectedMimeType}' is not allowed. Allowed types: ".implode(', ', self::$allowedMimeTypes)
            );
        }

        $fileSignature = substr($fileData, 0, 12);
        if (! self::validateFileSignature($fileSignature, $detectedMimeType, $fileData)) {
            throw new InvalidArgumentException('File signature does not match declared MIME type. Possible file type spoofing detected.');
        }

        return [
            'mime' => $detectedMimeType,
            'extension' => self::getExtensionFromMimeType($detectedMimeType),
        ];
    }

    private static function validateFileSignature(string $signature, string $mimeType, string $fullContent = ''): bool
    {
        $signatures = [
            'image/jpeg' => ["\xFF\xD8\xFF"],
            'image/png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
            'image/gif' => ["\x47\x49\x46\x38\x37\x61", "\x47\x49\x46\x38\x39\x61"],
            'image/webp' => ["\x52\x49\x46\x46"],
            'application/pdf' => ["\x25\x50\x44\x46"],
            'application/msword' => ["\xD0\xCF\x11\xE0"],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ["\x50\x4B\x03\x04"],
            'text/plain' => [],
        ];

        if ($mimeType === 'image/svg+xml') {
            $content = trim($fullContent ?: $signature);
            if (! preg_match('/<svg[\s>]/i', $content)) {
                return false;
            }
            if (preg_match('/<!ENTITY/i', $content) || preg_match('/SYSTEM\s+["\']/i', $content)) {
                return false;
            }
            if (preg_match('/<script/i', $content)) {
                return false;
            }

            return true;
        }

        if (! isset($signatures[$mimeType])) {
            return false;
        }

        if ($signatures[$mimeType] === []) {
            return true;
        }

        foreach ($signatures[$mimeType] as $expectedSignature) {
            if (str_starts_with($signature, $expectedSignature)) {
                if ($mimeType === 'image/webp') {
                    $fullContent = $fullContent ?: $signature;

                    return str_contains($fullContent, 'WEBP');
                }

                return true;
            }
        }

        return false;
    }

    private static function getExtensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
            default => 'bin',
        };
    }
}
