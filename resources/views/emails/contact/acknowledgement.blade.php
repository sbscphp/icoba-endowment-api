@extends('emails.layouts.base')

@php
    $subject = 'We received your message — '.$theme->brand_name;
    $headline = 'Thank you for contacting us, '.$recipientName.',';
    $lead = 'We have received your message and our team will review it shortly. Below is a summary of what you submitted.';
@endphp

@section('content')
    @include('emails.components.details-table', [
        'rows' => [
            ['label' => 'Reference', 'value' => $submission->uuid],
            ['label' => 'User type', 'value' => $userTypeLabel],
            ['label' => 'Submitted', 'value' => optional($submission->created_at)->format('F j, Y g:i A')],
        ],
    ])

    <p style="margin:16px 0 8px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }}; font-weight:600;">
        Your message
    </p>
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }}; white-space:pre-wrap;">{{ $submission->description }}</p>

    <p style="margin:0; font-size:14px; line-height:1.6; color:{{ $theme->muted_text_color }};">
        If you need to follow up, please reply to this email or contact us at
        <a href="mailto:{{ config('endowment.contact_email') }}" style="color:{{ $theme->linkColor() }};">{{ config('endowment.contact_email') }}</a>.
    </p>
@endsection
