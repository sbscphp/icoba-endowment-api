<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;background:#0f172a;font-family:system-ui,-apple-system,sans-serif;">
<div style="max-width:600px;margin:0 auto;padding:40px 24px;color:#e2e8f0;">
    @if(!empty($recipientName))
        <p style="margin:0 0 12px;font-size:14px;color:#94a3b8;">{{ $recipientName }}</p>
    @endif
    <div style="background:#1e293b;border-radius:8px;padding:24px;line-height:1.65;">
        {!! $bodyHtml !!}
    </div>
    <p style="margin-top:24px;font-size:11px;color:#64748b;">{{ $campaignName }}</p>
</div>
</body>
</html>
