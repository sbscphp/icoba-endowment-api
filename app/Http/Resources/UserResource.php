<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->roles->first();

        return [
            'uuid' => $this->uuid,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'middlename' => $this->middlename,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'country_code' => $this->country_code,
            'email_verified_at' => $this->email_verified_at,
            '2fa' => $this->{'2fa'},
            '2fa_expires_at' => $this->{'2fa_expires_at'},
            'is_active' => $this->is_active,
            'can_login' => $this->can_login,
            'role' => $role ? [
                'name' => $role->name,
                'guard_name' => $role->guard_name,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
