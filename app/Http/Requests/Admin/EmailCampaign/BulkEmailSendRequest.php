<?php

namespace App\Http\Requests\Admin\EmailCampaign;

use App\Http\Requests\ApiFormRequest;

class BulkEmailSendRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'confirm' => ['sometimes', 'boolean'],
        ];
    }
}
