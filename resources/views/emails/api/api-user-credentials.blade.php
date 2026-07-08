@extends('emails.layouts.base')

@php
    $subject = 'Your API credentials — '.$theme->brand_name;
    $headline = 'API credentials';
    $lead = 'Below are your client key, encryption material, and the transport mode configured for this integration. Store these secrets securely; treat the encryption key and IV like passwords.';
@endphp

@section('content')
    @include('emails.components.details-table', [
        'rows' => [
            ['label' => 'Client key (X-ClientKey)', 'value' => $apiUser->client_key],
            ['label' => 'Encryption mode', 'value' => $apiUser->encryption_mode->value],
        ],
    ])

    <p style="margin:0 0 8px 0; font-size:14px; font-weight:600; color:{{ $theme->accentColor() }};">Encryption key (base64)</p>
    <pre style="font-size:12px; white-space:pre-wrap; word-break:break-all; padding:12px; border:1px dashed {{ $theme->border_color }}; border-radius:8px; margin:0 0 16px 0; background:{{ $theme->background_color }};">{{ $apiUser->encryption_key }}</pre>

    <p style="margin:0 0 8px 0; font-size:14px; font-weight:600; color:{{ $theme->accentColor() }};">IV (base64)</p>
    <pre style="font-size:12px; white-space:pre-wrap; word-break:break-all; padding:12px; border:1px dashed {{ $theme->border_color }}; border-radius:8px; margin:0 0 16px 0; background:{{ $theme->background_color }};">{{ $apiUser->iv }}</pre>

    <p style="margin:0; font-size:13px; line-height:1.6; color:{{ $theme->muted_text_color }};">
        <strong>both</strong> — encrypted requests and responses;
        <strong>request_only</strong> — decrypt inbound payloads; plain JSON responses;
        <strong>response_only</strong> — plain JSON requests; encrypted JSON responses.
    </p>
@endsection
