<?php

declare(strict_types=1);

use App\Http\Controllers\GenericAssessmentResultPollController;
use App\Http\Middleware\AuthenticateSelectionResultPoll;
use Illuminate\Support\Facades\Route;

Route::get(
    '/integrations/v1/selection/assessment-attempts/{assessmentAttemptId}/result',
    GenericAssessmentResultPollController::class,
)
    ->where('assessmentAttemptId', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
    ->middleware(AuthenticateSelectionResultPoll::class)
    ->name('integrations.selection.assessment-results.poll');
