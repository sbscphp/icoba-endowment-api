<?php

namespace App\Http\Requests\Admin\EmailCampaign;

use App\Enums\BulkEmailAudience;
use App\Enums\EmailDesignTemplate;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CreateBulkEmailRequest extends ApiFormRequest
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
            'campaign_uuid' => ['required', 'string', Rule::exists('campaigns', 'uuid')],
            'title' => ['required', 'string', 'max:60'],
            'content' => ['required', 'string'],
            'design_template' => ['required', 'string', Rule::in(EmailDesignTemplate::values())],
            'recipient_audience' => ['required', 'array', 'min:1'],
            'recipient_audience.*' => ['string', Rule::in(BulkEmailAudience::values())],
            'action' => ['required', 'string', Rule::in(['draft', 'send_now'])],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'campaign_uuid.required' => 'Please select a campaign for this email.',
            'campaign_uuid.exists' => 'Selected campaign does not exist.',
            'title.max' => 'Email title may not be longer than 60 characters.',
            'content.required' => 'Please provide the email content.',
            'design_template.required' => 'Please select a design template.',
            'design_template.in' => 'Selected design template is invalid.',
            'recipient_audience.required' => 'Please select at least one recipient audience.',
            'recipient_audience.array' => 'Recipient audience must be provided as a list.',
            'recipient_audience.min' => 'Please select at least one recipient audience.',
            'recipient_audience.*.in' => 'One or more selected recipient audiences are invalid.',
            'action.required' => 'Please choose whether to save as draft or send now.',
            'action.in' => "Action must be either 'draft' or 'send_now'.",
        ]);
    }
}
