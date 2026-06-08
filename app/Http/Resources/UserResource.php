<?php

namespace App\Http\Resources;

use App\Enums\CustomerRegistrationStepEnum;
use App\Enums\DonorTypeSlug;
use App\Services\Customer\CustomerTierService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->roles->first();
        $tiers = app(CustomerTierService::class)->tiersForUser($this->resource);

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
            'donor' => $this->donorPayload(),
            'donation_tier' => $tiers['donation_tier'],
            'pledge_tier' => $tiers['pledge_tier'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function donorPayload(): array
    {
        $donorType = $this->relationLoaded('donorType') ? $this->donorType : $this->donorType()->first();
        $graduationSet = $this->relationLoaded('graduationSet') ? $this->graduationSet : $this->graduationSet()->first();
        $corporateCategory = $this->relationLoaded('corporateCategory') ? $this->corporateCategory : $this->corporateCategory()->first();

        $type = null;

        if ($donorType !== null) {
            $type = [
                'slug' => $donorType->slug,
                'label' => $donorType->label,
            ];
        } elseif (! empty($this->organization_name)) {
            $type = [
                'slug' => DonorTypeSlug::CORPORATE_DONOR->value,
                'label' => DonorTypeSlug::CORPORATE_DONOR->label(),
            ];
        } elseif (! empty($this->graduation_set_uuid) || ! empty($this->alumni_identifier)) {
            $type = [
                'slug' => DonorTypeSlug::ICOBA_ALUMNI->value,
                'label' => DonorTypeSlug::ICOBA_ALUMNI->label(),
            ];
        }

        return [
            'type' => $type,
            'organization_name' => $this->organization_name,
            'rc_number' => $this->rc_number,
            'tin' => $this->tin,
            'alumni_identifier' => $this->alumni_identifier,
            'set' => $graduationSet !== null
                ? $graduationSet->only(['uuid', 'name', 'set_number', 'public_id'])
                : null,
            'corporate_category' => $corporateCategory !== null
                ? $corporateCategory->only(['uuid', 'name'])
                : null,
        ];
    }
}
