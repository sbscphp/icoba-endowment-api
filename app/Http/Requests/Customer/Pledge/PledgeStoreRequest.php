<?php

namespace App\Http\Requests\Customer\Pledge;

use App\Enums\Currency;
use App\Enums\PledgePaymentPlanType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PledgeStoreRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
        }
    }

    public function rules(): array
    {
        return [
            'campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
            'user_uuid' => ['prohibited'],
            'donor_type_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:donor_types,uuid'],
            'graduation_set_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:sets,uuid'],
            'donor_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'donor_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'donor_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'committed_amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', Rule::in(Currency::values())],
            'exchange_rate_to_naira' => ['prohibited'],
            'payment_plan_type' => ['required', 'string', Rule::in(PledgePaymentPlanType::values())],
            'installment_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'schedule' => ['sometimes', 'nullable', 'array'],
            'with_placeholder_transaction' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
