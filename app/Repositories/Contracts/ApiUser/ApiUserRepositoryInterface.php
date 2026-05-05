<?php

namespace App\Repositories\Contracts\ApiUser;

use App\Http\Resources\ApiUserResource;
use App\Models\ApiUser;

interface ApiUserRepositoryInterface
{
    public function findByClientKey(string $clientKey): ?ApiUserResource;

    public function create(array $data): ApiUser;
}
