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

// F2 (2026-09-21), scoring wired in ADR-0032 PR2 (2026-09-23): transitions
// abandoned in_progress sessions to expired and scores each one
// (IST/PAPI/RMIB) in the same transaction as its seal. Without this, a
// session a participant simply walks away from stays in_progress in the
// database forever, since the status transition is otherwise only
// discovered lazily on the participant's own next request, which never
// comes.
Schedule::command('sessions:sweep-expired')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
