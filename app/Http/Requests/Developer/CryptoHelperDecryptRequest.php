<?php

declare(strict_types=1);

namespace App\Http\Requests\Developer;

use App\Http\Requests\ApiFormRequest;

final class CryptoHelperDecryptRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'response' => ['required', 'string', 'min:1', 'max:512000'],
        ];
    }
}
