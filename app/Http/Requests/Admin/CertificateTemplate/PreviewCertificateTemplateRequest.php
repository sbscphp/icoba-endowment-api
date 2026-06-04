<?php

namespace App\Http\Requests\Admin\CertificateTemplate;

use App\Enums\CertificatePreviewFormat;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PreviewCertificateTemplateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'awardee_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'format' => ['sometimes', 'nullable', 'string', Rule::in(CertificatePreviewFormat::values())],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'awardee_name.max' => 'Awardee name may not be longer than 120 characters.',
            'format.in' => 'Preview format is invalid.',
        ]);
    }
}
