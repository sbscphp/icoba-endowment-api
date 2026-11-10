@extends('emails.layouts.base')

@php
    $subject = 'Your '.$theme->brand_name.' account has been deleted';
    $headline = "Goodbye, {$recipientName},";
    $lead = 'Your '.$theme->brand_name.' account has been deleted as you requested. You can no longer sign in, and your personal details have been removed from your profile.';
    $supportEmail = $theme->support_email ?? 'support@icobaendowment.org';
@endphp

@section('content')
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">Donations you have already made remain part of the endowment's records, and any receipts issued to you stay valid.</p>

    @if ($pledgesCancelled > 0)
        <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">{{ $pledgesCancelled === 1 ? 'Your active pledge has' : $pledgesCancelled.' active pledges have' }} been cancelled, so you will not receive further payment reminders.</p>
    @endif

    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">Thank you for supporting {{ $theme->brand_name }}. You are welcome to register again at any time.</p>

    <p style="margin:0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">If you did not request this, please contact us at <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
@endsection
