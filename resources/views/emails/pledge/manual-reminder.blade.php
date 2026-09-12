@extends('emails.layouts.base')

@php
    $currency = strtoupper((string) $pledge->currency);
    $subject = ($isOverdue ? 'Overdue pledge payment reminder — ' : 'Pledge payment reminder — ').$theme->brand_name;
    $headline = 'Hello '.$recipientName.',';
    $dueFormatted = is_array($installment) && ! empty($installment['due_date'])
        ? \Carbon\Carbon::parse($installment['due_date'])->format('F j, Y')
        : null;
    $installmentDue = is_array($installment)
        ? number_format((float) ($installment['remaining_amount'] ?? 0), 2).' '.$currency
        : null;
    $installmentDueNgn = is_array($installment)
        ? '₦'.number_format((float) ($installment['remaining_amount_ngn'] ?? 0), 2)
        : null;

    if ($isOverdue && $dueFormatted !== null) {
        $lead = 'Your pledge installment for <strong>'.e($campaignName).'</strong> was due on <strong>'.$dueFormatted.'</strong> and is still outstanding. We would appreciate it if you could complete this payment at your earliest convenience.';
    } elseif ($dueFormatted !== null) {
        $lead = 'This is a reminder about your pledge to <strong>'.e($campaignName).'</strong>. Your next installment is due on <strong>'.$dueFormatted.'</strong>.';
    } else {
        $lead = 'This is a reminder about the outstanding balance on your pledge to <strong>'.e($campaignName).'</strong>.';
    }

    $rows = [
        ['label' => 'Campaign', 'value' => $campaignName],
        ['label' => 'Total pledged', 'value' => number_format((float) $pledge->committed_amount, 2).' '.$currency],
        ['label' => 'Paid so far', 'value' => number_format((float) $fulfilledAmount, 2).' '.$currency],
        ['label' => 'Outstanding balance', 'value' => number_format((float) $remainingAmount, 2).' '.$currency],
    ];
    if (is_array($installment)) {
        $rows[] = ['label' => 'Installment', 'value' => '#'.($installment['sequence'] ?? '—').' of '.($pledge->installment_count ?? '—')];
        if ($dueFormatted !== null) {
            $rows[] = ['label' => $isOverdue ? 'Was due on' : 'Due date', 'value' => $dueFormatted];
        }
        $rows[] = ['label' => 'Amount due', 'value' => $installmentDue];
        if ($currency !== 'NGN') {
            $rows[] = ['label' => 'Amount due (NGN)', 'value' => $installmentDueNgn];
        }
    }
    if ($overdueInstallments > 1) {
        $rows[] = ['label' => 'Overdue installments', 'value' => (string) $overdueInstallments];
    }
@endphp

@section('content')
    @include('emails.components.details-table', ['rows' => $rows])

    @if($note !== null && $note !== '')
        <p style="margin:0 0 8px 0; font-size:13px; font-weight:700; color:{{ $theme->muted_text_color }};">A note from the endowment team</p>
        <p style="margin:0 0 16px 0; padding:12px 16px; border-left:4px solid {{ $theme->accentColor() }}; background-color:{{ $theme->background_color }}; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">{{ $note }}</p>
    @endif

    @if($portalUrl)
        @include('emails.components.button', ['url' => $portalUrl, 'label' => 'View pledge and pay', 'theme' => $theme])
    @endif

    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">
        Please sign in to the endowment portal to complete this payment. If you have already made this payment, kindly disregard this reminder. Thank you for your continued support.
    </p>
@endsection
