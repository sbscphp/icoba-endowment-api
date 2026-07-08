<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #1a1a1a;
            margin: 0;
            padding: 18px;
        }
        .header-table,
        .meta-table,
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        .header-logo {
            height: 26px;
        }
        .brand {
            font-size: 16px;
            font-weight: bold;
            color: #111827;
        }
        .brand-sub {
            color: #6b7280;
            font-size: 9px;
            margin-top: 2px;
        }
        .title {
            text-align: right;
            font-size: 16px;
            font-weight: bold;
            color: #111827;
        }
        .title-sub {
            text-align: right;
            color: #6b7280;
            font-size: 9px;
            margin-top: 2px;
        }
        .separator {
            border-top: 2px solid #111827;
            margin: 12px 0 10px;
        }
        .meta-wrap {
            margin-bottom: 10px;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            background: #f9fafb;
        }
        .meta-table td {
            padding: 2px 0;
            vertical-align: top;
        }
        .meta-key {
            width: 70px;
            color: #6b7280;
            font-weight: bold;
        }
        .meta-value {
            color: #111827;
        }
        .warn {
            margin: 0 0 10px;
            padding: 8px 10px;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            background: #fffbeb;
            color: #92400e;
            font-weight: bold;
        }
        .report-table {
            margin-top: 10px;
        }
        .report-table th,
        .report-table td {
            border: 1px solid #d1d5db;
            padding: 6px 7px;
            text-align: left;
            vertical-align: top;
            word-break: break-word;
        }
        .report-table th {
            background: #111827;
            color: #ffffff;
            font-weight: bold;
            font-size: 8px;
        }
        .report-table tbody tr:nth-child(even) td {
            background: #f9fafb;
        }
        .report-table tbody tr:nth-child(odd) td {
            background: #ffffff;
        }
        .footer {
            margin-top: 12px;
            font-size: 8px;
            color: #6b7280;
            text-align: right;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td>
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" alt="{{ config('app.name') }}" class="header-logo">
                @else
                    <div class="brand">{{ config('app.name') }}</div>
                    <div class="brand-sub">Report Export</div>
                @endif
            </td>
            <td style="text-align: right;">
                <div class="title">{{ $title }}</div>
                <div class="title-sub">Generated {{ $generatedAt->timezone(config('app.timezone'))->format('Y-m-d H:i') }} ({{ config('app.timezone') }})</div>
            </td>
        </tr>
    </table>

    <div class="separator"></div>

    <div class="meta-wrap">
        <table class="meta-table">
            <tr>
                <td class="meta-key">Period</td>
                <td class="meta-value">{{ $periodStart }} — {{ $periodEnd }}</td>
            </tr>
            <tr>
                <td class="meta-key">Rows</td>
                <td class="meta-value">{{ number_format(count($rows)) }}</td>
            </tr>
        </table>
    </div>

    @if(!empty($truncated))
        <div class="warn">This PDF includes the first {{ number_format($includedRows) }} rows only. Use CSV export for the full dataset.</div>
    @endif

    <table class="report-table">
        <thead>
            <tr>
                @foreach($headers as $h)
                    <th>{{ $h }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="footer">{{ config('app.name') }} report · Rows shown: {{ number_format(count($rows)) }}</p>
</body>
</html>
