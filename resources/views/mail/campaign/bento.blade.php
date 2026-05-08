<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;background:#f4f4f5;font-family:system-ui,-apple-system,sans-serif;">
<div style="max-width:560px;margin:32px auto;padding:24px;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.08);">
    @if(!empty($recipientName))
        <p style="margin:0 0 16px;font-weight:600;">Hi {{ $recipientName }},</p>
    @endif
    <div style="font-size:15px;line-height:1.6;color:#374151;">
        {!! $bodyHtml !!}
    </div>
    <p style="margin-top:24px;font-size:12px;color:#9ca3af;">{{ $campaignName }}</p>
</div>
</body>
</html>
