@extends('emails.layouts.base')

@php
    $subject = $subject ?? \App\Mail\DonorRecognitionMail::subjectForTier($tierName);
    $headline = 'Congratulations, '.$recipientName.',';
    $lead = 'Thank you for your continued support. Based on your cumulative contributions, you have earned the '.$tierName.' recognition tier.';
@endphp

@section('content')
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $theme->text_color }};">
        Your personalized certificate is attached to this email. Your latest donation receipt is also attached for your records.
    </p>

    @include('emails.components.details-table', [
        'rows' => array_values(array_filter([
            ['label' => 'Recognition #', 'value' => $recognition->recognition_number],
            ['label' => 'Tier', 'value' => $tierName],
            ['label' => 'Awardee', 'value' => $recognition->awardee_name],
            optional($recognition->issued_at) ? ['label' => 'Issued At', 'value' => $recognition->issued_at->format('F j, Y')] : null,
        ])),
    ])

    @include('emails.components.button', ['url' => $certificateDownloadUrl, 'label' => 'Download certificate'])

    <p style="margin:16px 0 0 0; font-size:13px; line-height:1.6; color:{{ $theme->muted_text_color }};">
        You can also download your donation receipt here:
        <a href="{{ $donationReceiptDownloadUrl }}" style="color:{{ $theme->linkColor() }};">Download donation receipt</a>
    </p>
@endsection
