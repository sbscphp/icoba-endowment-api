<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    public static function make(): Password
    {
        return Password::min(12)
            ->mixedCase()
            ->numbers();
    }
}
