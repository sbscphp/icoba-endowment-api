<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('campaigns:auto-complete')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/campaigns-auto-complete.log'));

Schedule::command('contact-submissions:auto-close')
    ->daily()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/contact-submissions-auto-close.log'));

Schedule::command('pledges:send-payment-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/pledges-send-payment-reminders.log'));

Schedule::command('pledges:send-pause-resume-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/pledges-send-pause-resume-reminders.log'));

Schedule::command('sms:check-balance --scheduled')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/sms-balance.log'));

$exchangeRateFetchHours = max(1, min(12, (int) config('endowment.exchange_rate.fetch_interval_hours', 4)));

Schedule::command('exchange:fetch-rates')
    ->cron(sprintf('0 */%d * * *', $exchangeRateFetchHours))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/exchange-rates.log'));
