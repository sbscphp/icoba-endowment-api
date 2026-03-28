<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResendOtpPayloadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'challenge_token' => data_get($this->resource, 'payload.challenge_token'),
            'expires_in' => data_get($this->resource, 'payload.expires_in'),
        ];
    }
}
