@extends('emails.layouts.base')

@php
    $subject = 'New contact submission — '.$submission->full_name;
    $headline = 'New contact submission received';
    $lead = 'A visitor submitted the contact form on '.$theme->brand_name.'. Review the details below and follow up as needed.';
@endphp

@section('content')
    @include('emails.components.details-table', [
        'rows' => [
            ['label' => 'Reference', 'value' => $submission->uuid],
            ['label' => 'Name', 'value' => $submission->full_name],
            ['label' => 'Email', 'value' => $submission->email],
            ['label' => 'User type', 'value' => $userTypeLabel],
            ['label' => 'Submitted', 'value' => optional($submission->created_at)->format('F j, Y g:i A')],
        ],
    ])

    <p style="margin:16px 0 8px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }}; font-weight:600;">
        Message
    </p>
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }}; white-space:pre-wrap;">{{ $submission->description }}</p>
@endsection
