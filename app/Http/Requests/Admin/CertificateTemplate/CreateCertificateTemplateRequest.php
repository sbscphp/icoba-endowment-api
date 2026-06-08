<?php

namespace App\Http\Requests\Admin\CertificateTemplate;

use App\Enums\CertificateImageType;
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

            'design.lines' => ['sometimes', 'nullable', 'array', 'max:10'],
            'design.lines.*.text' => ['required_with:design.lines.*', 'string', 'max:1000'],
            'design.lines.*.font' => ['sometimes', 'nullable', 'string', 'max:60'],
            'design.lines.*.size' => ['sometimes', 'nullable', 'string', 'max:20'],

            'design.signatories' => ['sometimes', 'nullable', 'array', 'max:5'],
            'design.signatories.*.name' => ['required_with:design.signatories.*', 'string', 'max:120'],
            'design.signatories.*.position' => ['required_with:design.signatories.*', 'string', 'max:120'],
            'design.signatories.*.signature_url' => ['sometimes', 'nullable'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.unique' => 'A certificate template with this name already exists.',
            'name.max' => 'Template name may not be longer than 120 characters.',
            'tier_id.exists' => 'Selected tier does not exist.',
            'design.required' => 'Certificate design configuration is required.',
            'design.array' => 'Certificate design must be a structured object.',
            'design.image_type.required' => 'Please select a certificate image type.',
            'design.image_type.in' => 'Selected image type is invalid.',
            'design.lines.array' => 'Design lines must be provided as a list.',
            'design.lines.max' => 'You may define at most 10 design lines.',
            'design.lines.*.text.required_with' => 'Each design line requires text content.',
            'design.lines.*.text.max' => 'Design line text may not be longer than 1000 characters.',
            'design.signatories.array' => 'Signatories must be provided as a list.',
            'design.signatories.max' => 'You may define at most 5 signatories.',
            'design.signatories.*.name.required_with' => 'Each signatory requires a name.',
            'design.signatories.*.name.max' => 'Signatory name may not be longer than 120 characters.',
            'design.signatories.*.position.required_with' => 'Each signatory requires a position.',
            'design.signatories.*.position.max' => 'Signatory position may not be longer than 120 characters.',
        ]);
    }
}
