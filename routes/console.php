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

Schedule::command('notifications:dispatch-outbox')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('integrations:dispatch-outbox')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('integrations:reconcile-callbacks')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('integrations:dispatch-generic-result-callbacks --limit=25')
    ->everyFiveMinutes()
    ->name('generic-assessment-result-callback-dispatch')
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(static fn (): bool => (bool) config('selection_integration.result_callback_enabled'));
