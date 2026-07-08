<?php

namespace App\Models;

use App\Enums\OtpPurposeEnum;
use Illuminate\Database\Eloquent\Model;

class AuthChallenge extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurposeEnum::class,
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
