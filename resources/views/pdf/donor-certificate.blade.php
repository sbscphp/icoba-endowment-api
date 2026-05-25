<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Donor Certificate</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 0; }
        body {
            font-family: DejaVu Sans, sans-serif;
            margin: 0;
            padding: 0;
            color: #1f2937;
        }
        .page {
            position: relative;
            width: 100%;
            height: 100%;
            min-height: 720px;
            overflow: hidden;
        }
        .background {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 0;
        }
        .content {
            position: relative;
            z-index: 1;
            width: 100%;
            height: 100%;
            min-height: 720px;
            padding: 48px 64px;
        }
        .icon-wrap {
            text-align: {{ $iconPosition ?? 'center' }};
            margin-bottom: 12px;
        }
        .icon {
            max-width: 72px;
            max-height: 72px;
        }
        .awardee-name {
            font-family: {{ $awardeeFont ?? 'DejaVu Sans' }}, DejaVu Sans, sans-serif;
            font-size: {{ $awardeeFontSize ?? '28px' }};
            font-weight: {{ $awardeeFontWeight ?? 'bold' }};
            text-align: {{ $awardeeTextAlign ?? 'center' }};
            color: #122168;
            margin: 24px 0 18px 0;
            line-height: 1.3;
        }
        .line {
            margin: 8px 0;
            line-height: 1.5;
        }
        .signatories {
            position: absolute;
            left: 64px;
            right: 64px;
            bottom: 48px;
        }
        .signatories-table {
            width: 100%;
            border-collapse: collapse;
        }
        .signatories-table td {
            width: 50%;
            vertical-align: bottom;
            text-align: center;
            padding: 0 12px;
        }
        .signature-image {
            max-width: 120px;
            max-height: 48px;
            margin-bottom: 6px;
        }
        .signature-name {
            font-size: 12px;
            font-weight: bold;
            margin: 0;
        }
        .signature-position {
            font-size: 10px;
            color: #6b7280;
            margin: 4px 0 0 0;
        }
        .seal {
            position: absolute;
            right: 72px;
            bottom: 120px;
            max-width: 96px;
            max-height: 96px;
        }
        .meta {
            position: absolute;
            top: 36px;
            right: 64px;
            font-size: 9px;
            color: #6b7280;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="page">
        @if(!empty($backgroundDataUri))
            <img src="{{ $backgroundDataUri }}" alt="" class="background">
        @endif

        <div class="content">
            <div class="meta">
                {{ $recognitionNumber }}<br>
                {{ $issuedAt }}
            </div>

            @if(!empty($iconDataUri))
                <div class="icon-wrap">
                    <img src="{{ $iconDataUri }}" alt="" class="icon">
                </div>
            @endif

            <div class="awardee-name">{{ $awardeeName }}</div>

            @foreach($lines as $line)
                <div class="line" style="font-family: {{ $line['font'] }}, DejaVu Sans, sans-serif; font-size: {{ $line['size'] }}; font-weight: {{ $line['weight'] }}; text-align: {{ $line['position'] }};">
                    {{ $line['text'] }}
                </div>
            @endforeach

            @if(!empty($sealDataUri))
                <img src="{{ $sealDataUri }}" alt="" class="seal">
            @endif

            @if(!empty($signatories))
                <div class="signatories">
                    <table class="signatories-table">
                        <tr>
                            @foreach($signatories as $signatory)
                                <td>
                                    @if(!empty($signatory['signature_data_uri']))
                                        <img src="{{ $signatory['signature_data_uri'] }}" alt="" class="signature-image">
                                    @endif
                                    <p class="signature-name">{{ $signatory['name'] }}</p>
                                    <p class="signature-position">{{ $signatory['position'] }}</p>
                                </td>
                            @endforeach
                        </tr>
                    </table>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
