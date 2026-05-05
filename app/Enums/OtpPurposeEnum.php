<?php

namespace App\Enums;

enum OtpPurposeEnum: string
{
    case LOGIN = 'LOGIN';
    case PASSWORD_RESET = 'PASSWORD_RESET';
    case EMAIL_VERIFICATION = 'EMAIL_VERIFICATION';
}
