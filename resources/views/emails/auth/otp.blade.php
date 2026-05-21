@extends('emails.layouts.base')

@php
    $subject = match ($purpose) {
        \App\Enums\OtpPurposeEnum::PASSWORD_RESET => 'Password reset verification code',
        \App\Enums\OtpPurposeEnum::EMAIL_VERIFICATION => 'Verify your email address',
        default => 'Sign-in verification code',
    };
    $headline = "Hello {$recipientName},";
    $lead = match ($purpose) {
        \App\Enums\OtpPurposeEnum::PASSWORD_RESET => "We received a request to reset your password. Use the One-Time Password (OTP) below. It expires in {$expiresInMinutes} minutes.",
        \App\Enums\OtpPurposeEnum::EMAIL_VERIFICATION => 'Thank you for registering with the '.$theme->brand_name.' portal. Use the One-Time Password (OTP) below to verify your email address. It expires in '.$expiresInMinutes.' minutes.',
        default => 'We received a request to sign in to your account. To complete your login, please use the One-Time Password (OTP) provided below:',
    };
    $supportEmail = $theme->support_email ?? 'support@icoba.com';
@endphp

@section('content')
    @include('emails.components.details-table', ['rows' => [['label' => 'One-Time Password (OTP)', 'value' => $otp]]])
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">
        @switch($purpose)
            @case(\App\Enums\OtpPurposeEnum::PASSWORD_RESET)
                Enter this OTP on the password reset page to set a new password.
                @break
            @case(\App\Enums\OtpPurposeEnum::EMAIL_VERIFICATION)
                Enter this OTP to verify your email address and complete your registration.
                @break
            @default
                Enter this OTP on the login page to proceed and securely access your account.
        @endswitch
    </p>
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">If you did not initiate this request, please ignore this email or contact support immediately.</p>
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">Thank you,<br>The {{ $theme->brand_name }} Support Team</p>
    <p style="margin:0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">If you have any questions, please contact us at <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
@endsection
