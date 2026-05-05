@extends('emails.layouts.base')

@section('content')
    <h2 style="margin:0 0 8px 0; font-size:20px; line-height:1.3; color: {{ $theme->text_color }};">
        Reset your password
    </h2>

    <p style="margin:0 0 16px 0; color: {{ $theme->muted_text_color }}; font-size:14px; line-height:1.6;">
        We received a request to reset your password. Click the button below to set a new password.
        This link expires in {{ $expiresInMinutes }} minutes.
    </p>

    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:18px 0;">
        <tr>
            <td align="center" bgcolor="{{ $theme->primary_button_color }}" style="border-radius:10px;">
                <a href="{{ $resetUrl }}"
                    style="display:inline-block; padding:12px 18px; color: {{ $theme->primary_button_text_color }};
                          text-decoration:none; font-weight:700; font-size:14px;">
                    Reset Password
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0; color: {{ $theme->muted_text_color }}; font-size:13px; line-height:1.6;">
        If you didn’t request this, you can safely ignore this email.
    </p>

    <div
        style="margin-top:14px; padding-top:14px; border-top:1px dashed {{ $theme->border_color }}; color: {{ $theme->muted_text_color }}; font-size:12px; line-height:1.6;">
        If the button doesn’t work, copy and paste this link into your browser:<br>
        <span style="word-break:break-all;">{{ $resetUrl }}</span>
    </div>
@endsection
