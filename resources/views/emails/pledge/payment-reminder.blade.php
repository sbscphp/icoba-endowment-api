@extends('emails.layouts.base')

@php
    $subject = 'Upcoming pledge payment reminder — '.$theme->brand_name;
    $headline = 'Hello '.$recipientName.',';
    $daysBefore = (int) config('pledges.payment_reminder_days_before', 3);
    $lead = 'This is a friendly reminder that a scheduled pledge installment is due in '.$daysBefore.' day'.($daysBefore === 1 ? '' : 's').'.';
    $dueFormatted = \Carbon\Carbon::parse($dueDate)->format('F j, Y');
    $amount = number_format((float) $installment['remaining_amount'], 2);
    $currency = strtoupper((string) $installment['currency']);
    $amountNgn = number_format((float) $installment['remaining_amount_ngn'], 2);
@endphp

@section('content')
    @include('emails.components.details-table', [
        'rows' => [
            ['label' => 'Campaign', 'value' => $campaignName],
            ['label' => 'Due date', 'value' => $dueFormatted],
            ['label' => 'Amount due', 'value' => $amount.' '.$currency],
            ['label' => 'Amount due (NGN)', 'value' => '₦'.$amountNgn],
            ['label' => 'Installment', 'value' => '#'.($installment['sequence'] ?? '—')],
        ],
    ])

    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">
        Please sign in to the endowment portal to complete this payment. If you have paused your pledge, no action is required until you resume it.
    </p>
@endsection
