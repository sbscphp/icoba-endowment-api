<?php

namespace App\Services\Recognition;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class CertificateAssetResolver
{
    /**
     * @return array{url: ?string, data_uri: ?string}
     */
    public function resolve(mixed $source): array
    {
        if (! is_string($source) || trim($source) === '') {
            return ['url' => null, 'data_uri' => null];
        }

        $source = trim($source);

        if (str_starts_with($source, 'data:')) {
            return ['url' => null, 'data_uri' => $source];
        }

        $localDataUri = $this->resolveLocalDataUri($source);
        if ($localDataUri !== null) {
            return ['url' => $source, 'data_uri' => $localDataUri];
        }

        $binary = $this->downloadBinary($source);
        if ($binary === null) {
            Log::warning('Certificate asset could not be embedded.', ['source' => $source]);

            return ['url' => $source, 'data_uri' => null];
        }

        $maxEmbedBytes = max(32_768, (int) config('recognitions.certificate_asset_max_embed_bytes', 512_000));
        if (strlen($binary['body']) > $maxEmbedBytes) {
            return $this->resolveOptimizedEmbed($source, $maxEmbedBytes);
        }

        return [
            'url' => $source,
            'data_uri' => $this->toDataUri($binary['body'], $binary['mime']),
        ];
    }

    /**
     * @return array{url: ?string, data_uri: ?string}
     */
    private function resolveOptimizedEmbed(string $source, int $maxEmbedBytes): array
    {
        $optimizedUrl = $this->optimizedDeliveryUrl($source);
        $optimizedBinary = $this->fetchRemoteBinary($optimizedUrl);

        if ($optimizedBinary !== null && strlen($optimizedBinary['body']) <= $maxEmbedBytes) {
            return [
                'url' => $optimizedUrl,
                'data_uri' => $this->toDataUri($optimizedBinary['body'], $optimizedBinary['mime']),
            ];
        }

        if ($optimizedBinary === null) {
            Log::warning('Certificate asset optimized fetch failed.', ['source' => $source, 'url' => $optimizedUrl]);
        }

        return [
            'url' => $optimizedUrl,
            'data_uri' => null,
        ];
    }

    private function resolveLocalDataUri(string $source): ?string
    {
        $path = $this->resolveLocalPath($source);
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        $mime = $this->detectMimeType($contents, $path);

        return $this->toDataUri($contents, $mime);
    }

    private function resolveLocalPath(string $source): ?string
    {
        if (str_starts_with($source, '/')) {
            $publicPath = public_path(ltrim($source, '/'));

            return is_file($publicPath) ? $publicPath : null;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '' && str_starts_with($source, $appUrl.'/')) {
            $relative = ltrim(substr($source, strlen($appUrl)), '/');
            $publicPath = public_path($relative);

            return is_file($publicPath) ? $publicPath : null;
        }

        return null;
    }

    /**
     * @return array{body: string, mime: string}|null
     */
    private function downloadBinary(string $url): ?array
    {
        $cloudinaryPath = $this->resolveCloudinaryStoragePath($url);
        if ($cloudinaryPath !== null) {
            try {
                if (Storage::disk('cloudinary')->exists($cloudinaryPath)) {
                    $body = Storage::disk('cloudinary')->get($cloudinaryPath);
                    if (is_string($body) && $body !== '') {
                        return [
                            'body' => $body,
                            'mime' => $this->detectMimeType($body, $cloudinaryPath),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('Cloudinary disk read failed for certificate asset.', [
                    'path' => $cloudinaryPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->fetchRemoteBinary($url);
    }

    /**
     * @return array{body: string, mime: string}|null
     */
    private function fetchRemoteBinary(string $url): ?array
    {
        $timeout = max(5, (int) config('recognitions.certificate_asset_fetch_timeout', 30));
        $attempts = max(1, (int) config('recognitions.certificate_asset_fetch_attempts', 2));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::connectTimeout(10)
                    ->timeout($timeout)
                    ->get($url);

                if (! $response->successful()) {
                    continue;
                }

                $body = $response->body();
                if ($body === '') {
                    continue;
                }

                $mime = $response->header('Content-Type') ?: $this->guessMimeFromUrl($url);
                $mime = strtok($mime, ';') ?: $this->guessMimeFromUrl($url);

                return ['body' => $body, 'mime' => $mime];
            } catch (\Throwable $e) {
                if ($attempt === $attempts) {
                    Log::debug('Certificate asset HTTP fetch failed.', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return null;
    }

    private function resolveCloudinaryStoragePath(string $url): ?string
    {
        $cloudName = config('filesystems.disks.cloudinary.cloud');
        if (! is_string($cloudName) || $cloudName === '') {
            return null;
        }

        $pattern = '#^https://res\.cloudinary\.com/'.preg_quote($cloudName, '#').'/image/upload/(?:.*/)?(?:v\d+/)?(.+)$#i';
        if (! preg_match($pattern, $url, $matches)) {
            return null;
        }

        $path = ltrim((string) ($matches[1] ?? ''), '/');
        if ($path === '') {
            return null;
        }

        return rawurldecode($path);
    }

    private function detectMimeType(string $contents, string $referencePath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_buffer($finfo, $contents);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return $this->guessMimeFromUrl($referencePath);
    }

    private function guessMimeFromUrl(string $reference): string
    {
        $extension = strtolower(pathinfo(parse_url($reference, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }

    private function toDataUri(string $body, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($body);
    }

    private function optimizedDeliveryUrl(string $url): string
    {
        if (! str_contains($url, 'res.cloudinary.com') || ! str_contains($url, '/image/upload/')) {
            return $url;
        }

        if (preg_match('#/image/upload/([^/]+/)?v\d+/#', $url) === 1
            || preg_match('#/image/upload/(w_|c_|q_|f_)#', $url) === 1) {
            return preg_replace(
                '#/image/upload/#',
                '/image/upload/w_900,q_auto:good,f_auto/',
                $url,
                1,
            ) ?? $url;
        }

        return $url;
    }
}
