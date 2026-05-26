<?php

namespace App\Http\Requests\Admin\CertificateTemplate;

use App\Http\Requests\ApiFormRequest;

class PreviewCertificateTemplateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'awardee_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
