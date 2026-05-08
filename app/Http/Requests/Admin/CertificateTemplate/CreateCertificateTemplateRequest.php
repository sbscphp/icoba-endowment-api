<?php

namespace App\Http\Requests\Admin\CertificateTemplate;

use App\Enums\CertificateImageType;
use App\Enums\CertificateTextPosition;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CreateCertificateTemplateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('certificate_templates', 'name'),
            ],
            'tier_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('tier_configurations', 'uuid'),
            ],
            'is_active' => ['sometimes', 'boolean'],

            'design' => ['required', 'array'],
            'design.image_type' => ['required', 'string', Rule::in(CertificateImageType::values())],
            'design.image_url' => ['sometimes', 'nullable'],
            'design.general_text_position' => ['sometimes', 'nullable', 'string', Rule::in(CertificateTextPosition::values())],
            'design.icon_url' => ['sometimes', 'nullable'],
            'design.icon_position' => ['sometimes', 'nullable', 'string', Rule::in(CertificateTextPosition::values())],
            'design.seal_image_url' => ['sometimes', 'nullable'],
            'design.awardee_font' => ['sometimes', 'nullable', 'string', 'max:60'],
            'design.awardee_font_size' => ['sometimes', 'nullable', 'string', 'max:20'],
            'design.awardee_font_weight' => ['sometimes', 'nullable', 'string', 'max:20'],

            'design.lines' => ['sometimes', 'nullable', 'array', 'max:10'],
            'design.lines.*.text' => ['required_with:design.lines.*', 'string', 'max:1000'],
            'design.lines.*.font' => ['sometimes', 'nullable', 'string', 'max:60'],
            'design.lines.*.size' => ['sometimes', 'nullable', 'string', 'max:20'],
            'design.lines.*.weight' => ['sometimes', 'nullable', 'string', 'max:20'],
            'design.lines.*.position' => ['sometimes', 'nullable', 'string', Rule::in(CertificateTextPosition::values())],

            'design.signatories' => ['sometimes', 'nullable', 'array', 'max:5'],
            'design.signatories.*.name' => ['required_with:design.signatories.*', 'string', 'max:120'],
            'design.signatories.*.position' => ['required_with:design.signatories.*', 'string', 'max:120'],
            'design.signatories.*.signature_url' => ['sometimes', 'nullable'],
        ];
    }
}
