@extends('emails.layouts.base')

@php
    $subject = 'Set up your admin account';
    $recipientName = $admin->displayName() ?: 'there';
    $headline = "Welcome, {$recipientName}!";
    $brandName = $theme->brand_name ?? config('app.name');
    $lead = 'An administrator account has been created for you on the '.$brandName.' platform. Click the button below to set your password and activate your account. This link expires in '.$expiresInMinutes.' minutes.';
@endphp

@section('content')
    @include('emails.components.button', ['url' => $resetUrl, 'label' => 'Set Your Password'])

    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">For your security, choose a strong password you have not used elsewhere. You will be asked to sign in immediately after setting it.</p>

    <p style="margin:0 0 16px 0; font-size:13px; line-height:1.6; color:{{ $theme->muted_text_color }};">If you were not expecting this invitation, please contact your administrator or our support team right away.</p>

    <p style="margin:0; font-size:13px; line-height:1.6; color:{{ $theme->muted_text_color }};">If the button does not work, copy and paste this link into your browser:<br>
        <span style="word-break:break-all; color:{{ $theme->text_color }};">{{ $resetUrl }}</span>
    </p>
@endsection
