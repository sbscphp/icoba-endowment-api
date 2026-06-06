<?php

namespace App\Http\Requests\Admin\IssuedCertificate;

use App\Enums\CertificatePreviewFormat;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PreviewIssuedCertificateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'format' => ['sometimes', 'nullable', 'string', Rule::in(CertificatePreviewFormat::values())],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'format.in' => 'Preview format is invalid.',
        ]);
    }
}
