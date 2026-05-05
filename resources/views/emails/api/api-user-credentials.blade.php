@extends('emails.layouts.base', ['theme' => $theme])

@section('content')
    <div style="font-size:18px; font-weight:700; margin-bottom:8px;">
        API credentials
    </div>

    <div style="color: {{ $theme->muted_text_color }}; margin-bottom:16px;">
        Below are your client key, encryption material, and the transport mode configured for this integration.
        Store these secrets securely; treat the encryption key and IV like passwords.
    </div>

    <table role="presentation" cellspacing="0" cellpadding="0" style="width:100%; font-size:14px; margin-bottom:12px;">
        <tr>
            <td style="padding:8px 0; color:{{ $theme->muted_text_color }};">Client key (X-ClientKey)</td>
            <td style="padding:8px 0; text-align:right; word-break:break-all;">{{ $apiUser->client_key }}</td>
        </tr>
        <tr>
            <td style="padding:8px 0; color:{{ $theme->muted_text_color }};">Encryption mode</td>
            <td style="padding:8px 0; text-align:right;">{{ $apiUser->encryption_mode->value }}</td>
        </tr>
    </table>

    <div style="font-weight:600; margin:16px 0 8px;">Encryption key (base64)</div>
    <pre style="font-size:12px; white-space:pre-wrap; word-break:break-all; padding:12px; border:1px dashed {{ $theme->border_color }}; margin:0;">{{ $apiUser->encryption_key }}</pre>

    <div style="font-weight:600; margin:16px 0 8px;">IV (base64)</div>
    <pre style="font-size:12px; white-space:pre-wrap; word-break:break-all; padding:12px; border:1px dashed {{ $theme->border_color }}; margin:0;">{{ $apiUser->iv }}</pre>

    <div style="color: {{ $theme->muted_text_color }}; margin-top:16px; font-size:13px;">
        <strong>both</strong> — encrypted requests and responses;
        <strong>request_only</strong> — decrypt inbound payloads; plain JSON responses;
        <strong>response_only</strong> — plain JSON requests; encrypted JSON responses.
    </div>
@endsection
