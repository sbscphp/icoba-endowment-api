<?php

namespace App\Http\Requests\Concerns;

use App\Enums\OtpChannelEnum;
use Illuminate\Validation\Rule;

trait ValidatesOtpChannel
{
    /**
     * @return array<string, list<string|\Illuminate\Contracts\Validation\ValidationRule>>
     */
    protected function otpChannelRules(): array
    {
        return [
            'otp_channel' => ['nullable', 'string', Rule::in(OtpChannelEnum::values())],
        ];
    }

    protected function mergeDefaultOtpChannel(): void
    {
        if (! $this->filled('otp_channel')) {
            $this->merge(['otp_channel' => OtpChannelEnum::EMAIL->value]);
        }
    }
}
