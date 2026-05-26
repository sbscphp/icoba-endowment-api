<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Donor Certificate</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 0; size: A4 landscape; }
        html, body {
            font-family: DejaVu Sans, sans-serif;
            margin: 0;
            padding: 0;
            color: #1f2937;
            width: 100%;
            height: 100%;
        }
        .page {
            position: relative;
            width: 100%;
            min-height: 595px;
        }
        .background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 595px;
            z-index: 0;
        }
        .layout-table {
            position: relative;
            z-index: 1;
            width: 100%;
            min-height: 595px;
            border-collapse: collapse;
        }
        .meta-cell {
            padding: 28px 48px 8px 48px;
            text-align: center;
            font-size: 9px;
            color: #6b7280;
            line-height: 1.5;
        }
        .body-cell {
            padding: 12px 64px 160px 64px;
            text-align: center;
            vertical-align: middle;
        }
        .icon {
            width: 72px;
            height: auto;
            margin: 0 auto 12px auto;
        }
        .awardee-name {
            font-family: {{ $awardeeFont ?? 'DejaVu Sans' }}, DejaVu Sans, sans-serif;
            font-size: {{ $awardeeFontSize ?? '28px' }};
            font-weight: {{ $awardeeFontWeight ?? 'bold' }};
            text-align: {{ $awardeeTextAlign ?? 'center' }};
            color: #122168;
            margin: 0 0 18px 0;
            line-height: 1.3;
        }
        .line {
            margin: 8px auto;
            line-height: 1.5;
            max-width: 620px;
        }
        .signatories-cell {
            padding: 0 64px 40px 64px;
            vertical-align: bottom;
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
        .seal-wrap {
            text-align: center;
            margin-top: 16px;
        }
        .seal {
            width: 96px;
            height: auto;
        }
    </style>
</head>
<body>
    <div class="page">
        @if(!empty($backgroundDataUri))
            <img src="{{ $backgroundDataUri }}" alt="" class="background">
        @endif

        <table class="layout-table" cellpadding="0" cellspacing="0">
            <tr>
                <td class="meta-cell">
                    {{ $recognitionNumber }}<br>
                    {{ $issuedAt }}
                </td>
            </tr>
            <tr>
                <td class="body-cell" align="center" valign="middle">
                    @if(!empty($iconDataUri))
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td align="{{ $iconPosition === 'left' ? 'left' : ($iconPosition === 'right' ? 'right' : 'center') }}">
                                    <img src="{{ $iconDataUri }}" alt="" class="icon">
                                </td>
                            </tr>
                        </table>
                    @endif

                    <div class="awardee-name">{{ $awardeeName }}</div>

                    @foreach($lines as $line)
                        <div class="line" style="font-family: {{ $line['font'] }}, DejaVu Sans, sans-serif; font-size: {{ $line['size'] }}; font-weight: {{ $line['weight'] }}; text-align: {{ $line['position'] }};">
                            {{ $line['text'] }}
                        </div>
                    @endforeach

                    @if(!empty($sealDataUri))
                        <div class="seal-wrap">
                            <img src="{{ $sealDataUri }}" alt="" class="seal">
                        </div>
                    @endif
                </td>
            </tr>
            @if(!empty($signatories))
                <tr>
                    <td class="signatories-cell">
                        <table class="signatories-table" cellpadding="0" cellspacing="0">
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
                    </td>
                </tr>
            @endif
        </table>
    </div>
</body>
</html>
