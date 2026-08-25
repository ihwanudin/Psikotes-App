<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('test-numbers:prepare-month')
    ->monthlyOn(1, '00:00')
    ->timezone((string) config('participant_auth.test_number_timezone'))
    ->onOneServer();

Schedule::command('payments:reconcile-xendit')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
