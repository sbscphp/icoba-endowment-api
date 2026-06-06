<?php

namespace App\Http\Requests\Admin\EmailCampaign;

use App\Enums\BulkEmailAudience;
use App\Enums\EmailDesignTemplate;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateBulkEmailRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $r = $this->input('recipient_audience');
        if (is_string($r)) {
            $this->merge(['recipient_audience' => [$r]]);
        }
    }

    public function rules(): array
    {
        return [
            'campaign_uuid' => ['sometimes', 'string', Rule::exists('campaigns', 'uuid')],
            'title' => ['sometimes', 'string', 'max:60'],
            'content' => ['sometimes', 'string'],
            'design_template' => ['sometimes', 'string', Rule::in(EmailDesignTemplate::values())],
            'recipient_audience' => ['sometimes', 'array', 'min:1'],
            'recipient_audience.*' => ['string', Rule::in(BulkEmailAudience::values())],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'campaign_uuid.exists' => 'Selected campaign does not exist.',
            'title.max' => 'Email title may not be longer than 60 characters.',
            'design_template.in' => 'Selected design template is invalid.',
            'recipient_audience.array' => 'Recipient audience must be provided as a list.',
            'recipient_audience.min' => 'Please select at least one recipient audience.',
            'recipient_audience.*.in' => 'One or more selected recipient audiences are invalid.',
        ]);
    }
}
