<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $campaignName }}</title>
</head>
<body style="font-family: Georgia, serif; max-width: 640px; margin: 0 auto; padding: 24px; color: #1a1a1a;">
    @if(!empty($recipientName))
        <p>Dear {{ $recipientName }},</p>
    @endif
    <div style="margin-top: 16px;">
        {!! $bodyHtml !!}
    </div>
    <p style="margin-top: 32px; font-size: 12px; color: #666;">ICOBA Endowment &mdash; {{ $campaignName }}</p>
</body>
</html>
