<?php

namespace App\Http\Requests\Developer;

use App\Http\Requests\ApiFormRequest;

final class CryptoHelperEncryptRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plaintext' => ['required', 'string', 'max:512000'],
        ];
    }
}
