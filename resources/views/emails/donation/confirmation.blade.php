@extends('emails.layouts.base')

@php
    $subject = 'Donation confirmation from '.$theme->brand_name;
    $headline = 'Thank you for your donation, '.$recipientName.',';
    $lead = 'Your payment has been received successfully. Below are the details of your contribution.';
    $amount = number_format((float) $transaction->amount, 2);
    $currency = strtoupper((string) $transaction->currency);
    $amountNgn = $transaction->amount_in_naira !== null
        ? number_format((float) $transaction->amount_in_naira, 2)
        : null;
@endphp

@section('content')
    @include('emails.components.details-table', [
        'rows' => array_values(array_filter([
            ['label' => 'Receipt #', 'value' => $transaction->receipt_number ?? app(\App\Services\Receipt\ReceiptService::class)->receiptNumberFor($transaction)],
            ['label' => 'Campaign', 'value' => $campaignName],
            ['label' => 'Amount', 'value' => $amount.' '.$currency],
            $amountNgn !== null ? ['label' => 'Amount (NGN)', 'value' => '₦'.$amountNgn] : null,
            ['label' => 'Transaction ID', 'value' => $transaction->transaction_id],
            optional($transaction->paid_at) ? ['label' => 'Paid At', 'value' => $transaction->paid_at->format('F j, Y g:i A')] : null,
        ])),
    ])

    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">
        Your donation receipt is attached to this email. You can also download it using the link below.
    </p>

    @include('emails.components.button', ['url' => $donationReceiptDownloadUrl, 'label' => 'Download donation receipt'])

    <p style="margin:0 0 16px 0; font-size:13px; line-height:1.6;">
        If the button does not work, copy and paste this secure download link into your browser:<br>
        <span style="word-break:break-all;">{{ $donationReceiptDownloadUrl }}</span>
    </p>

    @if ($taxReceiptDownloadUrl !== null)
        <p style="margin:16px 0 0 0; font-size:13px; line-height:1.6; color:{{ $theme->muted_text_color }};">
            A separate tax exemption receipt is also available for your records:
            <a href="{{ $taxReceiptDownloadUrl }}" style="color:{{ $theme->linkColor() }};">Download tax receipt</a>
        </p>
    @endif
@endsection
