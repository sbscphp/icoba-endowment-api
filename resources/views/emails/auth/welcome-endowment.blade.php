@extends('emails.layouts.base')

@php
    $subject = 'Welcome to the '.$theme->brand_name.' portal';
    $headline = "Welcome, {$recipientName},";
    $lead = 'Your email address is now verified. Thank you for registering with '.$theme->brand_name.'—you can explore fundraising initiatives, track your impact, and manage your donations from your account.';
    $supportEmail = $theme->support_email ?? 'support@icoba.com';
@endphp

@section('content')
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">When you are ready, sign in to the portal using the button below. You can return whenever you wish to support our mission.</p>

    @include('emails.components.button', ['url' => $loginUrl, 'label' => 'Go to login'])

    <p style="margin:0 0 16px 0; font-size:13px; line-height:1.6; color:{{ $theme->muted_text_color }};">If the button does not work, copy and paste this address into your browser:<br>
        <span style="word-break:break-all; color:{{ $theme->text_color }};">{{ $loginUrl }}</span>
    </p>
    <p style="margin:0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">If you did not create this account, please contact us at <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
@endsection
