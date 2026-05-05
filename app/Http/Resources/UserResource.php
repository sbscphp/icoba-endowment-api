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
            'is_active' => $this->is_active,
            'can_login' => $this->can_login,
            'last_login_at' => $this->last_login_at,
            'last_active_at' => $this->last_active_at,
            'role' => $role ? [
                'name' => $role->name,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'donor' => $this->when($this->donor_type_uuid, function () {
                return [
                    'type' => $this->relationLoaded('donorType') && $this->donorType !== null
                        ? [
                            'slug' => $this->donorType->slug,
                            'label' => $this->donorType->label,
                        ]
                        : null,
                    'organization_name' => $this->organization_name,
                    'alumni_identifier' => $this->alumni_identifier,
                    'set' => $this->relationLoaded('graduationSet') && $this->graduationSet !== null
                        ? $this->graduationSet->only(['uuid', 'name', 'set_number', 'public_id'])
                        : null,
                    'corporate_category' => $this->relationLoaded('corporateCategory') && $this->corporateCategory !== null
                        ? $this->corporateCategory->only(['uuid', 'name'])
                        : null,
                ];
            }),
        ];
    }
}
