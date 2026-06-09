<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

trait ValidatesReconciliationUserIdentity
{
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
