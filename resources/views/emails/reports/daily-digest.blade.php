@extends('emails.layouts.base')

@php
    $summary = $report['summary'];
    $variance = $report['variance']['vs_previous_day'];
    $overdue = $report['overdue']['totals'];
    $subject = 'Daily donations & pledges digest — '.$report['report_date_label'];
    $headline = 'Daily digest for '.$report['report_date_label'];
    $lead = 'Here is the summary of donations and pledge activity. The full analytics report (PDF) and the overdue pledge follow-up list (CSV) are attached.';
    $ngn = fn ($v) => '₦'.number_format((float) $v, 2);
    $directionWord = match ($variance['direction']) {
        'increase' => 'up',
        'decrease' => 'down',
        default => 'flat',
    };
    $percentText = $variance['percent'] === null ? 'n/a' : $variance['percent'].'%';
    $adminUrl = rtrim((string) config('app.admin_frontend_url'), '/');
@endphp

@section('content')
    <p style="margin:0 0 8px 0; font-size:13px; font-weight:700; color:{{ $theme->muted_text_color }}; text-transform:uppercase; letter-spacing:0.04em;">Donations</p>
    @include('emails.components.details-table', [
        'rows' => [
            ['label' => 'Received yesterday', 'value' => $ngn($summary['donations_yesterday']['amount']).' ('.$summary['donations_yesterday']['count'].' donations)'],
            ['label' => 'Vs. the day before', 'value' => ucfirst($directionWord).' '.$percentText, 'color' => $variance['direction'] === 'decrease' ? '#b91c1c' : ($variance['direction'] === 'increase' ? '#15803d' : $theme->accentColor())],
            ['label' => 'Month to date', 'value' => $ngn($summary['donations_mtd']['amount']).' ('.$summary['donations_mtd']['count'].')'],
            ['label' => 'Year to date', 'value' => $ngn($summary['donations_ytd']['amount'])],
        ],
    ])

    <p style="margin:0 0 8px 0; font-size:13px; font-weight:700; color:{{ $theme->muted_text_color }}; text-transform:uppercase; letter-spacing:0.04em;">Pledges</p>
    @include('emails.components.details-table', [
        'rows' => [
            ['label' => 'Overdue amount', 'value' => $ngn($overdue['amount_ngn']), 'color' => (float) $overdue['amount_ngn'] > 0 ? '#b91c1c' : $theme->accentColor()],
            ['label' => 'Donors to follow up', 'value' => $overdue['donors'].' donor'.($overdue['donors'] === 1 ? '' : 's').' · '.$overdue['pledges'].' pledge'.($overdue['pledges'] === 1 ? '' : 's')],
            ['label' => 'Active pledges', 'value' => $summary['active_pledges']['count'].' · '.$summary['active_pledges']['collection_rate'].'% collected'],
            ['label' => 'Outstanding pledged', 'value' => $ngn($summary['active_pledges']['pending_ngn'])],
            ['label' => 'New pledges yesterday', 'value' => $summary['new_pledges_yesterday']['count'].' ('.$ngn($summary['new_pledges_yesterday']['committed_ngn']).')'],
            ['label' => 'Bank transfers awaiting verification', 'value' => $summary['awaiting_bank_verification']['count'].' ('.$ngn($summary['awaiting_bank_verification']['amount_ngn']).')'],
        ],
    ])

    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">
        Open the attached PDF for the trend and variance analysis, campaign performance, and the donor-by-donor overdue list with phone numbers and email addresses. The CSV contains every overdue pledge for sorting and filtering.
    </p>

    @if($adminUrl !== '')
        @include('emails.components.button', ['url' => $adminUrl, 'label' => 'Open admin portal'])
    @endif
@endsection
