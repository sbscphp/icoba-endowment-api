@extends('emails.layouts.base', ['theme' => $theme])

@section('content')
    <div style="font-size:18px; font-weight:700; margin-bottom:8px;">
        {{ $heading }}
    </div>

    <div style="color: {{ $theme->muted_text_color }}; margin-bottom:16px;">
        {{ $body }}
    </div>

    <div style="font-size:28px; letter-spacing:6px; font-weight:800; padding:14px 16px; border:1px dashed {{ $theme->border_color }}; text-align:center;">
        {{ $otp }}
    </div>

    <div style="color: {{ $theme->muted_text_color }}; margin-top:16px; font-size:13px;">
        If you didn't request this, ignore this email.
    </div>
@endsection
