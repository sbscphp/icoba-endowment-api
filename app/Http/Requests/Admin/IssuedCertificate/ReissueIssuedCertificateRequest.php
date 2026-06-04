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

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'awardee_name.max' => 'Awardee name may not be longer than 255 characters.',
            'send_email.boolean' => 'Send email flag must be true or false.',
        ]);
    }
}
