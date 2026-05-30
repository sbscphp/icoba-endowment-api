<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('campaigns:auto-complete')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('contact-submissions:auto-close')
    ->daily()
    ->withoutOverlapping();

Schedule::command('pledges:send-payment-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping();

$exchangeRateFetchHours = max(1, min(12, (int) config('endowment.exchange_rate.fetch_interval_hours', 4)));

Schedule::command('exchange:fetch-rates')
    ->cron(sprintf('0 */%d * * *', $exchangeRateFetchHours))
    ->withoutOverlapping();
