@extends('emails.layouts.base', ['theme' => $theme])

@section('content')
    <div style="font-size:18px; font-weight:700; margin-bottom:8px;">
        Welcome, {{ $recipientName }}
    </div>

    <div style="color: {{ $theme->muted_text_color }}; margin-bottom:16px; line-height:1.55;">
        Your email address is now verified. Thank you for registering with the ICOBA Endowment—you can explore
        fundraising initiatives, track your impact, and manage your donations from your account.
    </div>

    <div style="color: {{ $theme->text_color }}; margin-bottom:20px; line-height:1.55;">
        When you’re ready, sign in to the portal using the link below. You can return to this portal whenever you
        wish to support ICOBA’s mission.
    </div>

    <div style="margin: 24px 0;">
        <a href="{{ $loginUrl }}"
            style="display:inline-block; background: {{ $theme->secondary_color }}; color: #ffffff; text-decoration:none; font-weight:700; padding:12px 22px; border-radius:10px; font-size:15px;">
            Go to login
        </a>
    </div>

    <div style="color: {{ $theme->muted_text_color }}; font-size:13px; line-height:1.5;">
        If the button does not work, copy and paste this address into your browser:<br />
        <span style="word-break:break-all; color: {{ $theme->text_color }};">{{ $loginUrl }}</span>
    </div>

    <div style="color: {{ $theme->muted_text_color }}; margin-top:20px; font-size:13px;">
        If you did not create this account, please contact support so we can review the activity.
    </div>
@endsection
