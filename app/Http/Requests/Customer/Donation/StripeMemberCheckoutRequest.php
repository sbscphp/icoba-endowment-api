<?php

namespace App\Http\Requests\Customer\Donation;

use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\MergesCurrencyFromPledge;
use Illuminate\Validation\Rule;

class StripeMemberCheckoutRequest extends ApiFormRequest
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
            'user_uuid' => ['prohibited'],
            'donor_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'donor_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'donor_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'donor_type_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:donor_types,uuid'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'exchange_rate_to_naira' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'application_type' => ['sometimes', 'nullable', 'string', 'max:48'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'frontend_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'success_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'cancel_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }
}
