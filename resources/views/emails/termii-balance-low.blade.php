@extends('emails.layouts.base')

@php
    $subject = 'Termii Balance Low Alert';
    $headline = 'Termii Balance Low';
    $lead = 'Please top up your Termii account to ensure continuous SMS delivery.';
    $rows = [
        [
            'label' => 'Current Balance',
            'value' => $data['balance'] ?? 'Unknown',
        ],
    ];
    if (isset($data['monthly_budget_ngn'])) {
        $rows[] = [
            'label' => 'Monthly budget (NGN)',
            'value' => $data['monthly_budget_ngn'],
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
