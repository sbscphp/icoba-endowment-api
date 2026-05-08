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
}
