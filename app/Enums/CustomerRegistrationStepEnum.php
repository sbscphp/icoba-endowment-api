<?php

namespace App\Enums;

/**
 * Donor registration flow only: account created → confirm email OTP → done.
 * Login two-factor OTP is separate and does not change this step (it stays completed once email is verified).
 */
enum CustomerRegistrationStepEnum: string
{
    /** User registered (or must verify email on login); waiting for email OTP confirmation. */
    case AWAITING_OTP = 'awaiting_otp';

    /** Email OTP confirmed; registration is complete. */
    case COMPLETED = 'completed';
}
