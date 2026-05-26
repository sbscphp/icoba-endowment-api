<?php

namespace App\Http\Requests\Admin\Reconciliation;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;

class CompleteReconciliationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'user_uuid' => ['nullable', 'uuid', 'exists:users,uuid'],
            'campaign_uuid' => ['nullable', 'uuid', 'exists:campaigns,uuid'],
            'pledge_uuid' => ['nullable', 'uuid', 'exists:pledges,uuid'],
            'reconciliation_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
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
        });
    }
}
