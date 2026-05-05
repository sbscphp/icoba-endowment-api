<?php

namespace App\Repositories\ApiUser;

use App\Http\Resources\ApiUserResource;
use App\Models\ApiUser;
use App\Repositories\Contracts\ApiUser\ApiUserRepositoryInterface;

final class ApiUserRepository implements ApiUserRepositoryInterface
{
    public function findByClientKey(string $clientKey): ?ApiUserResource
    {
        $user = ApiUser::query()
            ->where('client_key', $clientKey)
            ->where('is_active', true)
            ->first();

        return $user !== null ? new ApiUserResource($user) : null;
    }

    public function create(array $data): ApiUser
    {
        return ApiUser::create($data);
    }
}
