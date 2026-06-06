@extends('emails.layouts.base')

@php
    $provider = \Illuminate\Support\Str::title($data['provider'] ?? 'SMS');
    $currency = $data['currency'] ?? '';
    $balance = $data['balance'] ?? 'Unknown';
    $balanceDisplay = is_numeric($balance) && $currency !== ''
        ? $currency.' '.number_format((float) $balance, 2)
        : $balance;

    $subject = "{$provider} Balance Low Alert";
    $headline = "{$provider} Balance Low";
    $lead = "Please top up your {$provider} account to ensure continuous SMS delivery.";
    $rows = [
        [
            'label' => 'Current Balance',
            'value' => $balanceDisplay,
        ],
    ];
    if (isset($data['monthly_budget'])) {
        $budgetLabel = $currency !== '' ? "Monthly budget ({$currency})" : 'Monthly budget';
        $rows[] = [
            'label' => $budgetLabel,
            'value' => is_numeric($data['monthly_budget'])
                ? number_format((float) $data['monthly_budget'], 2)
                : $data['monthly_budget'],
        ];
    }
    if (isset($data['percentage_used'])) {
        $rows[] = [
            'label' => 'Budget used',
            'value' => $data['percentage_used'].'%',
        ];
    }
    if (isset($data['threshold_percent'])) {
        $rows[] = [
            'label' => 'Threshold crossed',
            'value' => $data['threshold_percent'].'%',
        ];
    }
@endphp

@section('content')
    @include('emails.components.details-table', ['rows' => $rows])
@endsection
