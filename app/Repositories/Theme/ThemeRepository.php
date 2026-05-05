<?php

namespace App\Repositories\Theme;

use App\Models\Theme;
use App\Repositories\Contracts\Theme\ThemeRepositoryInterface;

class ThemeRepository implements ThemeRepositoryInterface
{
    public function getDefault(): Theme
    {
        return Theme::where('is_default', true)->first()
            ?? Theme::query()->first()
            ?? new Theme(['name' => 'Fallback']);
    }

    public function findById(int $id): ?Theme
    {
        return Theme::find($id);
    }
}
