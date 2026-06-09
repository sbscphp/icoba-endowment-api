<?php

namespace App\Http\Requests\Admin\Reconciliation;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ValidatesGuestDonorProfileFields;
use App\Http\Requests\Concerns\ValidatesReconciliationUserIdentity;
use Illuminate\Contracts\Validation\Validator;

class CompleteReconciliationRequest extends ApiFormRequest
{
    use ValidatesGuestDonorProfileFields;
    use ValidatesReconciliationUserIdentity;

    protected function prepareForValidation(): void
    {
        $this->prepareReconciliationUserIdentityForValidation();

        if (! $this->filled('user_uuid') && ! $this->filled('user_identity')) {
            $this->prepareGuestDonorProfileForValidation();
        }
    }

    public function rules(): array
    {
        $shared = array_merge([
            'user_uuid' => ['nullable', 'uuid', 'exists:users,uuid'],
            'campaign_uuid' => ['nullable', 'uuid', 'exists:campaigns,uuid'],
            'pledge_uuid' => ['nullable', 'uuid', 'exists:pledges,uuid'],
            'reconciliation_note' => ['nullable', 'string', 'max:1000'],
            'is_anonymous' => ['sometimes', 'boolean'],
        ], $this->userIdentityFieldRules());

        if ($this->filled('user_identity')) {
            return array_merge($shared, $this->prohibitedWithUserIdentityRules());
        }

        if ($this->filled('user_uuid')) {
            return array_merge($shared, $this->prohibitedDonorProfileRules(), [
                'user_identity' => ['prohibited'],
            ]);
        }

        if ($this->filled('donor_type') || $this->filled('donor_type_uuid')) {
            return array_merge(
                $shared,
                $this->guestDonorProfileRulesForSlug($this->resolvedGuestDonorTypeSlug()),
            );
        }

        return array_merge($shared, [
            'donor_type' => ['sometimes', 'nullable', 'string'],
            'donor_type_uuid' => ['sometimes', 'nullable', 'uuid'],
            'donor_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'donor_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'donor_phone.regex' => 'Please enter a valid phone number for the selected country.',
            'set_number.exists' => 'I couldn\'t find that set. Please double-check the graduation year.',
            'donor_type.prohibited' => 'Provide either user_uuid or donor profile fields, not both.',
            'donor_email.prohibited' => 'Provide either user_uuid or donor profile fields, not both.',
            'user_identity.prohibited' => 'Provide either user_identity, user_uuid, or donor profile fields, not more than one.',
            'user_identity.exists' => 'Selected giving identity does not exist.',
            'user_identity.uuid' => 'user_identity must be a valid giving identity UUID.',
            'user_uuid.exists' => 'Selected donor account does not exist. Use user_identity from donor search instead.',
            'user_uuid.prohibited' => 'Provide either user_identity or user_uuid, not both.',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $this->appendUserIdentityExclusivityValidation($validator);

        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $hasCampaign = $this->filled('campaign_uuid');
            $hasPledge = $this->filled('pledge_uuid');

            if (! $hasCampaign && ! $hasPledge) {
                $validator->errors()->add(
                    'campaign_uuid',
                    'Provide either a campaign or a pledge for reconciliation.',
                );
            }

            if ($this->filled('user_uuid') && $this->hasDonorProfileInput()) {
                $validator->errors()->add(
                    'user_uuid',
                    'Provide either user_uuid or donor profile fields, not both.',
                );
            }

            if ($this->filled('user_uuid') && $this->filled('user_identity')) {
                $validator->errors()->add(
                    'user_uuid',
                    'Provide either user_identity or user_uuid, not both.',
                );
            }
        });

        if (
            ! $this->filled('user_uuid')
            && ! $this->filled('user_identity')
            && ($this->filled('donor_type') || $this->filled('donor_type_uuid'))
        ) {
            $this->appendGuestDonorProfileValidation($validator);
        } elseif (! $this->filled('user_uuid') && ! $this->filled('user_identity') && $this->filled('donor_email')) {
            $this->appendNewDonorUniquenessValidation($validator);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function prohibitedDonorProfileRules(): array
    {
        $prohibited = ['prohibited'];

        return [
            'donor_type' => $prohibited,
            'donor_type_uuid' => $prohibited,
            'donor_email' => $prohibited,
            'donor_phone' => $prohibited,
            'country_uuid' => $prohibited,
            'country_code' => $prohibited,
            'firstname' => $prohibited,
            'lastname' => $prohibited,
            'set_number' => $prohibited,
            'alumni_identifier' => $prohibited,
            'organization_name' => $prohibited,
            'corporate_category_uuid' => $prohibited,
            'rc_number' => $prohibited,
            'tin' => $prohibited,
        ];
    }

    private function hasDonorProfileInput(): bool
    {
        foreach ([
            'donor_type',
            'donor_type_uuid',
            'donor_email',
            'donor_phone',
            'firstname',
            'lastname',
            'set_number',
            'alumni_identifier',
            'organization_name',
            'corporate_category_uuid',
            'rc_number',
            'tin',
        ] as $field) {
            if ($this->filled($field)) {
                return true;
            }
        }

        return false;
    }
}
