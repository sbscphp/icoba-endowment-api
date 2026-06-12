@extends('emails.layouts.base')

@php
    $subject = $subject ?? 'Certificate revoked: '.$recognition->recognition_number;
    $headline = 'Certificate revoked';
    $lead = 'Hello '.$recipientName.', your recognition certificate has been revoked by an administrator and is no longer available for download.';
@endphp

@section('content')
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6;">
        This notice is to let you know that the certificate below is no longer valid. If you believe this was done in error,
        please contact support for assistance.
    </p>

    @include('emails.components.details-table', [
        'rows' => array_values(array_filter([
            ['label' => 'Recognition #', 'value' => $recognition->recognition_number],
            filled($tierName) ? ['label' => 'Tier', 'value' => $tierName] : null,
            ['label' => 'Awardee', 'value' => $recognition->awardee_name],
            optional($recognition->issued_at) ? ['label' => 'Issued At', 'value' => $recognition->issued_at->format('F j, Y')] : null,
            ['label' => 'Status', 'value' => 'Revoked'],
        ])),
    ])
@endsection
