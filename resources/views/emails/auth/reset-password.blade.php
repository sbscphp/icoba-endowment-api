@extends('emails.layouts.base')

@php
    $subject = 'Reset your password';
    $recipientName = $user->displayName() ?: 'there';
    $headline = "Hello {$recipientName},";
    $lead = 'We received a request to reset your password. Click the button below to set a new password. This link expires in '.$expiresInMinutes.' minutes.';
@endphp

@section('content')
    @include('emails.components.button', ['url' => $resetUrl, 'label' => 'Reset Password'])

    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">If you did not request this, you can safely ignore this email.</p>

    <p style="margin:0; font-size:13px; line-height:1.6; color:{{ $theme->muted_text_color }};">If the button does not work, copy and paste this link into your browser:<br>
        <span style="word-break:break-all; color:{{ $theme->text_color }};">{{ $resetUrl }}</span>
    </p>
@endsection
