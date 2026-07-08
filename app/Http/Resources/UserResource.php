<?php

namespace App\Http\Resources;

use App\Enums\CustomerRegistrationStepEnum;
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
            'email_verified_at' => $this->email_verified_at,
            'registration_step' => $this->email_verified_at
                ? CustomerRegistrationStepEnum::COMPLETED->value
                : CustomerRegistrationStepEnum::AWAITING_OTP->value,
            'phone_number' => $this->phone_number,
            'country_code' => $this->country_code,
            '2fa' => $this->{'2fa'},
            'email_notifications_enabled' => (bool) $this->email_notifications_enabled,
            'push_notifications_enabled' => (bool) $this->push_notifications_enabled,
            'biometrics_enabled' => (bool) $this->biometrics_enabled,
            'is_active' => $this->is_active,
            'can_login' => $this->can_login,
            'last_login_at' => $this->last_login_at,
            'last_active_at' => $this->last_active_at,
            'role' => $role ? [
                'name' => $role->name,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
