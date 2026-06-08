@extends('emails.layouts.base')

@php
    $resumeFormatted = \Carbon\Carbon::parse($resumeDate)->format('F j, Y');
    $isResumeDay = $reminderKind === 'on_resume_date';
    $daysBefore = $isResumeDay ? null : (int) strtok($reminderKind, '_');

    if ($isResumeDay) {
        $subject = 'Your pledge has resumed — '.$theme->brand_name;
        $headline = 'Hello '.$recipientName.',';
        $lead = 'Your pledge has been automatically resumed as scheduled on '.$resumeFormatted.'.';
    } else {
        $subject = 'Your pledge will resume soon — '.$theme->brand_name;
        $headline = 'Hello '.$recipientName.',';
        $lead = 'This is a reminder that your paused pledge is scheduled to automatically resume on '.$resumeFormatted
            .($daysBefore > 0 ? ' ('.$daysBefore.' day'.($daysBefore === 1 ? '' : 's').' from now).' : '.');
    }
@endphp

@section('content')
    @include('emails.components.details-table', [
        'rows' => array_values(array_filter([
            ['label' => 'Campaign', 'value' => $campaignName],
            ['label' => 'Resume date', 'value' => $resumeFormatted],
            $isResumeDay && is_array($nextInstallment) ? [
                'label' => 'Next due date',
                'value' => \Carbon\Carbon::parse($nextInstallment['due_date'])->format('F j, Y'),
            ] : null,
            $isResumeDay && is_array($nextInstallment) ? [
                'label' => 'Amount due',
                'value' => number_format((float) $nextInstallment['remaining_amount'], 2).' '.strtoupper((string) $nextInstallment['currency']),
            ] : null,
            $isResumeDay && is_array($nextInstallment) ? [
                'label' => 'Amount due (NGN)',
                'value' => '₦'.number_format((float) $nextInstallment['remaining_amount_ngn'], 2),
            ] : null,
        ])),
    ])

    @if (! $isResumeDay)
        <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">
            While your pledge is paused, scheduled payments are on hold. On the resume date above, your pledge will automatically resume and your payment schedule will continue. No action is required unless you wish to resume earlier or update your pause date from the endowment portal.
        </p>
    @else
        <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">
            @if (is_array($nextInstallment))
                Your pledge is active again. Please sign in to the endowment portal to review your schedule and complete your next installment when it is due.
            @else
                Your pledge is active again. Please sign in to the endowment portal to review your payment schedule.
            @endif
        </p>
    @endif
@endsection
