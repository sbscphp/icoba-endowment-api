<?php

namespace App\Enums;

enum AuditActionEnum: string
{
    case REGISTERED = 'REGISTERED';
    case EMAIL_VERIFIED = 'EMAIL_VERIFIED';
    case LOGIN_SUCCESS = 'LOGIN_SUCCESS';
    case LOGIN_FAILED = 'LOGIN_FAILED';
    case OTP_SENT = 'OTP_SENT';
    case OTP_VERIFIED = 'OTP_VERIFIED';
    case OTP_FAILED = 'OTP_FAILED';
    case PASSWORD_RESET_REQUESTED = 'PASSWORD_RESET_REQUESTED';
    case PASSWORD_RESET_COMPLETED = 'PASSWORD_RESET_COMPLETED';
    case PROFILE_UPDATED = 'PROFILE_UPDATED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
