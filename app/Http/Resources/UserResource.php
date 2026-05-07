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
            'is_active' => $this->is_active,
            'can_login' => $this->can_login,
            'last_login_at' => $this->last_login_at,
            'last_active_at' => $this->last_active_at,
            'role' => $role ? [
                'name' => $role->name,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'donor' => $this->donorPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function donorPayload(): array
    {
        $type = null;

        if ($this->relationLoaded('donorType') && $this->donorType !== null) {
            $type = [
                'slug' => $this->donorType->slug,
                'label' => $this->donorType->label,
            ];
        } elseif (! empty($this->organization_name)) {
            $type = [
                'slug' => 'corporate_donor',
                'label' => 'Corporate Donor',
            ];
        } elseif (! empty($this->graduation_set_uuid) || ! empty($this->alumni_identifier)) {
            $type = [
                'slug' => 'icoba_alumni',
                'label' => 'ICOBA Alumni',
            ];
        } else {
            $type = [
                'slug' => 'friends_relatives',
                'label' => 'Friends & Relatives',
            ];
        }

        return [
            'type' => $type,
            'organization_name' => $this->organization_name,
            'alumni_identifier' => $this->alumni_identifier,
            'set' => $this->relationLoaded('graduationSet') && $this->graduationSet !== null
                ? $this->graduationSet->only(['uuid', 'name', 'set_number', 'public_id'])
                : null,
            'corporate_category' => $this->relationLoaded('corporateCategory') && $this->corporateCategory !== null
                ? $this->corporateCategory->only(['uuid', 'name'])
                : null,
        ];
    }
}
