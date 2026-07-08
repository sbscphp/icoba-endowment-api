<?php

namespace App\Http\Resources\Developer;

use App\Enums\Api\ApiEncryptionMode;
use App\Models\ApiUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApiUser
 */
class ApiUserResource extends JsonResource
{
    /**
     * @param  ApiUser  $resource
     */
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->resource->uuid,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'client_key' => $this->resource->client_key,
            'encryption_key' => $this->resource->encryption_key,
            'iv' => $this->resource->iv,
            'encryption_mode' => $this->resource->encryption_mode->value,
            'is_active' => (bool) $this->resource->is_active,
        ];
    }

    public function getEncryptionKey(): string
    {
        return $this->resource->encryption_key;
    }

    public function getIv(): string
    {
        return $this->resource->iv;
    }

    public function getEncryptionMode(): ApiEncryptionMode
    {
        return $this->resource->encryption_mode;
    }

    public function isActive(): bool
    {
        return (bool) $this->resource->is_active;
    }
}
