<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TokenRefreshResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'access_token' => data_get($this->resource, 'access_token'),
            'refresh_token' => data_get($this->resource, 'refresh_token'),
            'token_type' => data_get($this->resource, 'token_type'),
            'expires_in' => data_get($this->resource, 'expires_in'),
            'refresh_expires_in' => data_get($this->resource, 'refresh_expires_in'),
        ];
    }
}
