<?php

use App\Jobs\SendAgreementExpiryNotificationsJob;
use App\Jobs\SendRentReminderJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SendRentReminderJob)
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new SendAgreementExpiryNotificationsJob)
    ->dailyAt('10:00')
    ->withoutOverlapping()
    ->onOneServer();
