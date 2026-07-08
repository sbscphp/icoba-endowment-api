<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $report->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 0; padding: 24px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .header-table td { vertical-align: top; }
        .brand-name { font-size: 15px; font-weight: bold; color: #122168; margin: 0 0 4px 0; }
        .meta-right { text-align: right; font-size: 10px; color: #374151; line-height: 1.6; }
        .badge { display: inline-block; background: #dcfce7; color: #166534; font-size: 9px; font-weight: bold; letter-spacing: 0.5px; padding: 5px 10px; border-radius: 999px; text-transform: uppercase; }
        .report-title { font-size: 22px; font-weight: bold; color: #122168; margin: 0 0 8px 0; }
        .report-subtitle { font-size: 12px; color: #6b7280; line-height: 1.6; margin: 0 0 18px 0; }
        .banner { width: 100%; max-height: 220px; object-fit: cover; border-radius: 8px; margin-bottom: 18px; }
        .section-title { font-size: 12px; font-weight: bold; color: #111827; margin: 0 0 8px 0; }
        .details { font-size: 11px; color: #374151; line-height: 1.7; }
        .details p { margin: 0 0 10px 0; }
        .youtube { font-size: 10px; color: #122168; margin-top: 14px; word-break: break-all; }
        .footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; text-align: center; line-height: 1.5; }
        .logo { max-width: 90px; max-height: 48px; margin-bottom: 8px; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 58%;">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" alt="Logo" class="logo">
                @endif
                <p class="brand-name">{{ $campaign?->name ?? 'Campaign Update Report' }}</p>
            </td>
            <td style="width: 42%;" class="meta-right">
                <span class="badge">Campaign Report</span><br><br>
                <strong>Report ID:</strong> {{ $report->report_id }}<br>
                <strong>Generated:</strong> {{ $generatedAt->format('d M Y, H:i') }}
            </td>
        </tr>
    </table>

    @if(!empty($report->banner_url))
        <img src="{{ $report->banner_url }}" alt="Report banner" class="banner">
    @endif

    <h1 class="report-title">{{ $report->name }}</h1>
    <p class="report-subtitle">{{ $report->short_description }}</p>

    <div class="section-title">Report Details</div>
    <div class="details">{!! $report->details !!}</div>

    @if(!empty($report->youtube_link))
        <div class="youtube"><strong>Video:</strong> {{ $report->youtube_link }}</div>
    @endif

    <div class="footer">
        Generated on {{ $generatedAt->format('d M Y') }} &middot; {{ $report->report_id }}
    </div>
</body>
</html>
