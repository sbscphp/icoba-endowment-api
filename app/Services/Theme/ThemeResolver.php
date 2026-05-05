<?php

namespace App\Services\Theme;

use App\Models\Theme;
use Illuminate\Support\Facades\Cache;
use App\Repositories\Contracts\Theme\ThemeRepositoryInterface;

class ThemeResolver
{
    public function __construct(private ThemeRepositoryInterface $themes) {}

    public function resolveForMail(?int $themeId = null): Theme
    {
        $cacheKey = $themeId ? "theme.mail.{$themeId}" : "theme.mail.default";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($themeId) {
            if ($themeId) {
                return $this->themes->findById($themeId) ?? $this->themes->getDefault();
            }
            return $this->themes->getDefault();
        });
    }

    public function flush(?int $themeId = null): void
    {
        Cache::forget($themeId ? "theme.mail.{$themeId}" : "theme.mail.default");
    }
}
