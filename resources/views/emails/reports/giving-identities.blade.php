@extends('emails.layouts.base')

@php
    $subject = 'Giving identity report — '.$generatedAt;
    $headline = 'Giving identity report';
    $lead = 'Snapshot taken '.e($generatedAt).'. The attached Excel file lists every giving identity, the account behind it, how it was created, what it has given, and anything that needs attention.';
    $ngn = fn ($v) => '₦'.number_format((float) $v, 2);
    $attentionColor = fn (int $count) => $count > 0 ? '#b91c1c' : $theme->accentColor();
@endphp

@section('content')
    <p style="margin:0 0 8px 0; font-size:13px; font-weight:700; color:{{ $theme->muted_text_color }}; text-transform:uppercase; letter-spacing:0.04em;">Identities</p>
    @include('emails.components.details-table', [
        'rows' => [
            ['label' => 'Total giving identities', 'value' => (string) $summary['total_identities']],
            ['label' => 'Linked to an account', 'value' => (string) $summary['with_account']],
            ['label' => 'Guest only', 'value' => (string) $summary['guest_only']],
            ['label' => 'Locked by a donation', 'value' => (string) $summary['locked']],
            ['label' => 'Total donated via identities', 'value' => $ngn($summary['total_ngn'])],
        ],
    ])

    <p style="margin:0 0 8px 0; font-size:13px; font-weight:700; color:{{ $theme->muted_text_color }}; text-transform:uppercase; letter-spacing:0.04em;">Needs a look</p>
    @include('emails.components.details-table', [
        'rows' => [
            ['label' => 'Identities needing attention', 'value' => (string) $summary['needs_attention'], 'color' => $attentionColor((int) $summary['needs_attention'])],
            ['label' => 'In conflict', 'value' => (string) ($summary['by_status']['conflict'] ?? 0), 'color' => $attentionColor((int) ($summary['by_status']['conflict'] ?? 0))],
            ['label' => 'Account profile differs from identity', 'value' => (string) $summary['account_profile_differs'], 'color' => $attentionColor((int) $summary['account_profile_differs'])],
            ['label' => 'Registered users without an identity', 'value' => $summary['users_without_identity'].' of '.$summary['total_users']],
            ['label' => 'Donor emails with unattached donations', 'value' => (string) $summary['donor_emails_without_identity']],
        ],
    ])

    <p style="margin:0 0 8px 0; font-size:13px; font-weight:700; color:{{ $theme->muted_text_color }}; text-transform:uppercase; letter-spacing:0.04em;">How identities were created</p>
    @include('emails.components.details-table', [
        'rows' => collect($summary['by_source'])
            ->sortDesc()
            ->map(fn ($count, $source) => ['label' => ucwords(str_replace('_', ' ', (string) $source)), 'value' => (string) $count])
            ->values()
            ->all(),
    ])

    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">
        The Summary sheet explains every status and source. Filter the "Needs attention" column on the Giving identities sheet to see which records to follow up.
    </p>
@endsection
