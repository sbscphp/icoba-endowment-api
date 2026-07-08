@extends('emails.layouts.base')

@php
    $subject = 'NHEF Nexus Exchange Rate Alert';
    $headline = 'Exchange Rate Alert';
    $lead = $alertMessage;
@endphp
