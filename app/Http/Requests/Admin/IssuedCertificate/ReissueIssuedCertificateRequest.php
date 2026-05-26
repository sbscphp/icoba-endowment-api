<?php

namespace App\Http\Requests\Admin\IssuedCertificate;

use App\Http\Requests\ApiFormRequest;

class ReissueIssuedCertificateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'awardee_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'send_email' => ['sometimes', 'boolean'],
        ];
    }
}
