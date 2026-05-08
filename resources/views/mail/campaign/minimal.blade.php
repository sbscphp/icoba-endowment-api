<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#111827;">
@if(!empty($recipientName))
    <p style="margin:0 0 8px;">{{ $recipientName }}</p>
@endif
<div style="max-width:100%;">
    {!! $bodyHtml !!}
</div>
<p style="margin-top:40px;font-size:11px;color:#6b7280;">{{ $campaignName }}</p>
</body>
</html>
