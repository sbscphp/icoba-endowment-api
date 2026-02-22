<?php

namespace App\Repositories\Contracts\Theme;

use App\Models\Theme;

interface ThemeRepositoryInterface
{
    public function getDefault(): Theme;
    public function findById(int $id): ?Theme;
}
