<?php

namespace App\Http\Requests\Customer\Donation;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesCurrencyFromPledge;
use App\Models\User;
use Illuminate\Validation\Rule;

class DonationIntentRequest extends ApiFormRequest
{
    use MergesCurrencyFromPledge;

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => [
                Rule::requiredIf(fn () => ! $this->filled('pledge_uuid')),
                'nullable',
                'string',
                Rule::in(Currency::values()),
            ],
            'campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
            'pledge_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:pledges,uuid'],
            'user_uuid' => [
                Rule::prohibitedIf($this->user() instanceof User),
                'sometimes',
                'nullable',
                'uuid',
                'exists:users,uuid',
            ],
            'donor_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'donor_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'donor_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'donor_type_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:donor_types,uuid'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'gateway' => ['sometimes', 'nullable', 'string', 'max:64'],
            'schedule_item_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
