@extends('emails.layouts.base')

@php
    $subject = 'ICOBA Endowment Exchange Rate Alert';
    $headline = 'Exchange Rate Alert';
    $lead = $alertMessage;
@endphp
