@extends('emails.layouts.base')

@php
    $subject = $campaignName;
    if (! empty($recipientName)) {
        $headline = "Hello {$recipientName},";
    }
    $brandSubtitle = $campaignName;
@endphp

@section('content')
    <div style="font-size:14px; line-height:1.65; color:{{ $theme->text_color }};">
        {!! $bodyHtml !!}
    </div>
@endsection
