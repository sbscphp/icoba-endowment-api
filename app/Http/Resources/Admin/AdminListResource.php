<?php

namespace App\Http\Resources\Admin;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin row for user-management listing tables.
 *
 * @mixin Admin
 */
class AdminListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('roles:id,name');

        return [
            'admin_id' => $this->uuid,
            'name' => $this->name,
            'role' => $this->roles->first()?->name,
            'email' => $this->email,
            'last_active' => $this->last_active_at,
            'status' => $this->is_active ? 'active' : 'inactive',
        ];
    }
}
