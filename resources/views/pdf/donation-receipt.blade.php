<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $badgeLabel ?? 'Donation receipt' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 0; padding: 24px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .header-table td { vertical-align: top; }
        .brand-name { font-size: 15px; font-weight: bold; color: #122168; margin: 0 0 4px 0; }
        .brand-meta { font-size: 10px; color: #6b7280; line-height: 1.5; }
        .badge { display: inline-block; background: #fbbf24; color: #111827; font-size: 9px; font-weight: bold; letter-spacing: 0.5px; padding: 5px 10px; border-radius: 999px; text-transform: uppercase; }
        .meta-right { text-align: right; font-size: 10px; color: #374151; line-height: 1.6; }
        .amount-box { background: #f3f4f6; border-radius: 8px; padding: 18px; text-align: center; margin: 18px 0; }
        .amount-label { font-size: 11px; color: #6b7280; margin-bottom: 6px; }
        .amount-value { font-size: 28px; font-weight: bold; color: #122168; margin: 0; }
        .section-title { font-size: 12px; font-weight: bold; color: #111827; margin: 0 0 8px 0; }
        .info-box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; }
        .info-table { width: 100%; border-collapse: collapse; }
        .info-table td { padding: 5px 0; vertical-align: top; }
        .info-label { width: 34%; color: #6b7280; font-size: 10px; }
        .info-value { color: #111827; font-size: 11px; font-weight: 600; }
        .tax-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 14px; margin: 14px 0; }
        .tax-title { font-size: 11px; font-weight: bold; color: #92400e; margin: 0 0 6px 0; }
        .tax-text { font-size: 10px; color: #78350f; line-height: 1.6; margin: 0; }
        .thank-you { font-size: 10px; color: #374151; line-height: 1.6; margin: 16px 0 24px 0; }
        .signature-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .signature-name { font-family: DejaVu Sans, sans-serif; font-size: 18px; font-style: italic; color: #122168; margin: 0 0 4px 0; }
        .signature-meta { font-size: 10px; color: #6b7280; line-height: 1.5; }
        .footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; text-align: center; line-height: 1.5; }
        .logo { max-width: 90px; max-height: 48px; margin-bottom: 8px; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 58%;">
                @if(!empty($logoDataUri))
                    <img src="{{ $logoDataUri }}" alt="Logo" class="logo">
                @endif
                <p class="brand-name">{{ $foundationName }}</p>
                <div class="brand-meta">
                    Tax ID: {{ $taxId }}<br>
                    {{ $contactEmail }}<br>
                    {{ $website }}
                </div>
            </td>
            <td style="width: 42%;" class="meta-right">
                <span class="badge">{{ $badgeLabel }}</span><br><br>
                <strong>Receipt #:</strong> {{ $receiptNumber }}<br>
                <strong>Issued Date:</strong> {{ $issuedAt }}
            </td>
        </tr>
    </table>

    <div class="amount-box">
        <div class="amount-label">Total Donation Amount</div>
        <p class="amount-value">{{ $amountFormatted }}</p>
    </div>

    <p class="section-title">Donor Information</p>
    <div class="info-box">
        <table class="info-table">
            <tr>
                <td class="info-label">Name</td>
                <td class="info-value">{{ $donorName ?? '—' }}</td>
            </tr>
            @if(!empty($donorEmail))
            <tr>
                <td class="info-label">Email</td>
                <td class="info-value">{{ $donorEmail }}</td>
            </tr>
            @endif
        </table>
    </div>

    <p class="section-title">Donation Details</p>
    <div class="info-box">
        <table class="info-table">
            <tr>
                <td class="info-label">Donation Date</td>
                <td class="info-value">{{ $donationDate }}</td>
            </tr>
            <tr>
                <td class="info-label">Payment Method</td>
                <td class="info-value">{{ $paymentMethod }}</td>
            </tr>
            <tr>
                <td class="info-label">Designated to</td>
                <td class="info-value">{{ $campaignName }}</td>
            </tr>
            @if(!empty($tierLabel))
            <tr>
                <td class="info-label">Donor Tier</td>
                <td class="info-value">{{ $tierLabel }}</td>
            </tr>
            @endif
        </table>
    </div>

    <div class="tax-box">
        <p class="tax-title">Tax Deductibility Statement</p>
        <p class="tax-text">{{ $taxStatement }}</p>
    </div>

    <p class="thank-you">{{ $thankYouMessage }}</p>

    <table class="signature-table">
        <tr>
            <td style="width: 60%;">
                <p class="signature-name">{{ $executiveDirectorName }}</p>
                <div class="signature-meta">
                    {{ $executiveDirectorTitle }}<br>
                    {{ $foundationName }}
                </div>
            </td>
            <td style="width: 40%; text-align: right; vertical-align: bottom;">
                <div class="signature-meta"><strong>Date of Issue</strong><br>{{ $issuedAt }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        This is an official donation receipt. For questions regarding this donation, please contact us at
        {{ $contactEmail }} or visit {{ $website }}.
    </div>
</body>
</html>
