@extends('emails.layouts.base')

@php
    $subject = 'Your tax receipt from '.$theme->brand_name;
    $headline = 'Thank you for your corporate donation, '.$recipientName.',';
    $lead = 'Your payment has been received. Attached is your official tax exemption receipt for your records. You can also download your receipts using the links below.';
    $amount = number_format((float) $transaction->amount, 2);
    $currency = strtoupper((string) $transaction->currency);
@endphp

@section('content')
    @include('emails.components.details-table', [
        'rows' => array_values(array_filter([
            ['label' => 'Receipt #', 'value' => $transaction->receipt_number ?? app(\App\Services\Receipt\ReceiptService::class)->receiptNumberFor($transaction)],
            ['label' => 'Amount', 'value' => $amount.' '.$currency],
            optional($transaction->paid_at) ? ['label' => 'Paid At', 'value' => $transaction->paid_at->format('F j, Y g:i A')] : null,
        ])),
    ])

    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">
        Please retain this tax receipt for your tax records. No goods or services were provided in exchange for this donation.
    </p>

    @include('emails.components.button', ['url' => $taxReceiptDownloadUrl, 'label' => 'Download tax receipt'])

    <p style="margin:16px 0 0 0; font-size:13px; line-height:1.6; color:{{ $theme->muted_text_color }};">
        You can also download your standard donation receipt here:
        <a href="{{ $donationReceiptDownloadUrl }}" style="color:{{ $theme->linkColor() }};">Download donation receipt</a>
    </p>
@endsection
