<?php

namespace App\Http\Resources\Auth;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoginPayloadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'access_token' => data_get($this->resource, 'access_token'),
            'token_type' => data_get($this->resource, 'token_type'),
            'user' => UserResource::make(data_get($this->resource, 'user')),
        ];
    }
}
