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
}
