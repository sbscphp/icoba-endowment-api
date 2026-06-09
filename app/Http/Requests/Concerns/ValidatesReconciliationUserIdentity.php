<?php

namespace App\Http\Requests\Concerns;

use App\Models\GivingIdentity;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesReconciliationUserIdentity
{
    protected function prepareReconciliationUserIdentityForValidation(): void
    {
        if (! $this->filled('user_identity') && $this->filled('giving_identity_uuid')) {
            $this->merge(['user_identity' => $this->input('giving_identity_uuid')]);
        }

        $givingIdentity = $this->input('giving_identity');
        if (
            ! $this->filled('user_identity')
            && is_array($givingIdentity)
            && filled($givingIdentity['uuid'] ?? null)
        ) {
            $this->merge(['user_identity' => $givingIdentity['uuid']]);
        }

        if (! $this->filled('user_identity') && $this->filled('user_uuid')) {
            $candidate = trim((string) $this->input('user_uuid'));
            $existsAsUser = User::query()->where('uuid', $candidate)->exists();
            $existsAsIdentity = GivingIdentity::query()->where('uuid', $candidate)->exists();

            if (! $existsAsUser && $existsAsIdentity) {
                $this->merge(['user_identity' => $candidate]);
                $this->offsetUnset('user_uuid');
            }
        }

        if (! $this->filled('user_identity')) {
            return;
        }

        foreach ($this->reconciliationUserIdentityConflictFields() as $field) {
            $this->offsetUnset($field);
        }
    }

    /**
     * @return list<string>
     */
    protected function reconciliationUserIdentityConflictFields(): array
    {
        return [
            'user_uuid',
            'uuid',
            'giving_identity_uuid',
            'giving_identity',
            'donor_type',
            'donor_type_uuid',
            'donor_email',
            'donor_phone',
            'donor_name',
            'email',
            'phone_number',
            'country_uuid',
            'country_code',
            'firstname',
            'lastname',
            'set_number',
            'alumni_identifier',
            'organization_name',
            'corporate_category_uuid',
            'rc_number',
            'tin',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected function userIdentityFieldRules(): array
    {
        return [
            'user_identity' => ['nullable', 'uuid', 'exists:giving_identities,uuid'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected function prohibitedWithUserIdentityRules(): array
    {
        return array_merge($this->prohibitedDonorProfileRules(), [
            'user_uuid' => ['prohibited'],
        ]);
    }

    protected function appendUserIdentityExclusivityValidation(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('user_identity')) {
                return;
            }

            if ($this->filled('user_uuid')) {
                $validator->errors()->add(
                    'user_identity',
                    'Provide either user_identity, user_uuid, or donor profile fields, not more than one.',
                );
            }

            if ($this->hasDonorProfileInput()) {
                $validator->errors()->add(
                    'user_identity',
                    'Provide either user_identity or donor profile fields, not both.',
                );
            }
        });
    }
}
