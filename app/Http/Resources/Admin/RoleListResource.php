<?php

namespace App\Http\Resources\Admin;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'role_id' => $this->uuid,
            'name' => $this->name,
            'number_of_users' => (int) ($this->users_count ?? 0),
            'status' => $this->is_active ? 'active' : 'inactive',
            'last_updated' => $this->updated_at,
        ];
    }
}
