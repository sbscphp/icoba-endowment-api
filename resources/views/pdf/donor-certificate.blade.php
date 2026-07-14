<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Donor Certificate</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @page { margin: 0; size: A4 landscape; }
        html, body {
            font-family: DejaVu Sans, sans-serif;
            margin: 0;
            padding: 0;
            color: #1f2937;
            width: 842px;
            height: 595px;
        }
        .page {
            position: relative;
            width: 842px;
            height: 595px;
            margin: 0;
            padding: 0;
        }
        .background {
            position: absolute;
            top: 0;
            left: 0;
            width: 842px;
            height: 595px;
            z-index: 0;
            margin: 0;
            padding: 0;
            border: 0;
            display: block;
        }
        .side-image {
            position: absolute;
            top: 0;
            width: 269px;
            height: 100%;
            margin: 0;
            padding: 0;
            border: 0;
            display: block;
            z-index: 1;
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
        }
        .side-image--left { left: 0; }
        .side-image--right { right: 0; background-position: center center; }
        .content-panel {
            position: absolute;
            top: 0;
            height: 595px;
            z-index: 2;
            margin: 0;
            padding: 0;
        }
        .content-panel--left-image { left: 269px; width: 573px; }
        .content-panel--right-image { left: 0; width: 573px; }
        .content-panel--full { left: 0; width: 842px; }
        .content-layout {
            width: 100%;
            height: 595px;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .meta-cell {
            padding: 16px 40px 4px 40px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
            line-height: 1.5;
        }
        .meta-number {
            font-size: 14px;
            font-weight: 700;
            color: #122168;
        }
        .meta-date {
            font-size: 12px;
            color: #6b7280;
        }
        .body-cell {
            padding: 8px 40px;
            text-align: center;
            vertical-align: middle;
        }
        .icon-wrap {
            margin-bottom: 10px;
        }
        .icon {
            width: 120px;
            height: auto;
            display: block;
            margin: 0 auto;
        }
        .awardee-name {
            font-family: {{ $awardeeFont ?? 'DejaVu Sans' }}, DejaVu Sans, sans-serif;
            font-size: {{ $awardeeFontSize ?? '28px' }};
            font-weight: {{ $awardeeFontWeight ?? 'bold' }};
            text-align: {{ $awardeeTextAlign ?? 'center' }};
            color: #122168;
            margin: 8px auto 6px auto;
            line-height: 1.3;
        }
        .awardee-rule {
            width: 72%;
            margin: 0 auto 12px auto;
            border-top: 1px dashed #9ca3af;
            height: 0;
        }
        .line {
            margin: 6px auto;
            line-height: 1.5;
            max-width: 500px;
        }
        .signatories-cell {
            padding: 0 40px 40px 40px;
            vertical-align: bottom;
        }
        .signatories-table {
            width: 100%;
            border-collapse: collapse;
        }
        .signatories-table td {
            vertical-align: bottom;
            text-align: center;
            padding: 0 8px;
        }
        .signatory-col { width: 33%; }
        .seal-col { width: 34%; }
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
            width: 96px;
            height: auto;
            margin: 0 auto;
        }
    </style>
</head>
<body>
@php
    $sideImageSrc = $sideImageDataUri ?: ($sideImageUrl ?? null);
    $backgroundSrc = $backgroundDataUri ?: ($backgroundUrl ?? null);
    $iconSrc = $iconDataUri ?: ($iconUrl ?? null);
    $sealSrc = $sealDataUri ?: ($sealUrl ?? null);
    $linesBeforeName = $linesBeforeName ?? [];
    $linesAfterName = $linesAfterName ?? [];
    $isSideLayout = in_array($imageType ?? 'background', ['image_left', 'image_right'], true);
    $isImageRight = ($imageType ?? 'background') === 'image_right';
    $contentPanelClass = $isSideLayout
        ? ($isImageRight ? 'content-panel--right-image' : 'content-panel--left-image')
        : 'content-panel--full';
@endphp

<div class="page">
    @if(!$isSideLayout && !empty($backgroundSrc))
        <img src="{{ $backgroundSrc }}" alt="" class="background">
    @endif

    @if($isSideLayout && !empty($sideImageSrc))
        <div
            class="side-image {{ $isImageRight ? 'side-image--right' : 'side-image--left' }}"
            style="background-image: url('{{ $sideImageSrc }}');"
        ></div>
    @endif

    <div class="content-panel {{ $contentPanelClass }}">
        <table class="content-layout" cellpadding="0" cellspacing="0" height="595">
            <tr>
                <td class="meta-cell" height="8%" valign="top">
                    <div class="meta-number">{{ $recognitionNumber }}</div>
                    <div class="meta-date">{{ $issuedAt }}</div>
                </td>
            </tr>
            <tr>
                <td class="body-cell" height="52%" align="center" valign="middle">
                    @if(!empty($iconSrc))
                        <div class="icon-wrap">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="{{ $iconPosition === 'left' ? 'left' : ($iconPosition === 'right' ? 'right' : 'center') }}">
                                        <img src="{{ $iconSrc }}" alt="" class="icon">
                                    </td>
                                </tr>
                            </table>
                        </div>
                    @endif

                    @foreach($linesBeforeName as $line)
                        <div class="line" style="font-family: {{ $line['font'] }}, DejaVu Sans, sans-serif; font-size: {{ $line['size'] }}; font-weight: {{ $line['weight'] }}; text-align: {{ $line['position'] }};">
                            {{ $line['text'] }}
                        </div>
                    @endforeach

                    <div class="awardee-name">{{ $awardeeName }}</div>
                    <div class="awardee-rule"></div>

                    @foreach($linesAfterName as $line)
                        <div class="line" style="font-family: {{ $line['font'] }}, DejaVu Sans, sans-serif; font-size: {{ $line['size'] }}; font-weight: {{ $line['weight'] }}; text-align: {{ $line['position'] }};">
                            {{ $line['text'] }}
                        </div>
                    @endforeach
                </td>
            </tr>
            @if(!empty($leftSignatory) || !empty($rightSignatory) || !empty($sealSrc))
                <tr>
                    <td class="signatories-cell" height="40%" valign="bottom">
                        <table class="signatories-table" cellpadding="0" cellspacing="0">
                            <tr>
                                <td class="signatory-col">
                                    @if(!empty($leftSignatory))
                                        @if(!empty($leftSignatory['signature_data_uri']) || !empty($leftSignatory['signature_url']))
                                            <img src="{{ $leftSignatory['signature_data_uri'] ?: $leftSignatory['signature_url'] }}" alt="" class="signature-image">
                                        @endif
                                        <p class="signature-name">{{ $leftSignatory['name'] }}</p>
                                        <p class="signature-position">{{ $leftSignatory['position'] }}</p>
                                    @endif
                                </td>
                                <td class="seal-col">
                                    @if(!empty($sealSrc))
                                        <img src="{{ $sealSrc }}" alt="" class="seal">
                                    @endif
                                </td>
                                <td class="signatory-col">
                                    @if(!empty($rightSignatory))
                                        @if(!empty($rightSignatory['signature_data_uri']) || !empty($rightSignatory['signature_url']))
                                            <img src="{{ $rightSignatory['signature_data_uri'] ?: $rightSignatory['signature_url'] }}" alt="" class="signature-image">
                                        @endif
                                        <p class="signature-name">{{ $rightSignatory['name'] }}</p>
                                        <p class="signature-position">{{ $rightSignatory['position'] }}</p>
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            @else
                <tr>
                    <td class="signatories-cell" height="40%" valign="bottom">&nbsp;</td>
                </tr>
            @endif
        </table>
    </div>
</div>
</body>
</html>
