<?php

declare(strict_types=1);

use App\Http\Controllers\AssessmentParticipantProvisioningController;
use App\Http\Controllers\AssessmentResultController;
use App\Http\Controllers\ParticipantEntitlementController;
use App\Http\Controllers\ParticipantLoginController;
use App\Http\Controllers\ParticipantOrderStatusController;
use App\Http\Controllers\ParticipantProfileController;
use App\Http\Controllers\SelectionParticipantProvisioningController;
use App\Http\Controllers\StartParticipantSessionController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/v1/selection/participants', SelectionParticipantProvisioningController::class)
    ->middleware(['throttle:selection-integration', 'selection.integration'])
    ->name('integrations.selection.participants.store');

Route::post('/integrations/v1/assessments/participants', AssessmentParticipantProvisioningController::class)
    ->middleware(['throttle:selection-integration', 'integration.client'])
    ->name('integrations.assessments.participants.store');

Route::get('/integrations/v1/assessments/participants/{externalCandidateId}/result', AssessmentResultController::class)
    ->where('externalCandidateId', '[A-Za-z0-9._\/-]{1,100}')
    ->middleware(['throttle:selection-integration', 'integration.client'])
    ->name('integrations.assessments.participants.result');

Route::post('/auth/participant/login', ParticipantLoginController::class)
    ->middleware('throttle:participant-login')
    ->name('participant.login');

Route::middleware(['participant.jwt', 'rls'])->group(function (): void {
    Route::get('/me', ParticipantProfileController::class)->name('participant.me');
    Route::get('/me/entitlements', ParticipantEntitlementController::class)
        ->name('participant.entitlements');
    Route::get('/me/order', ParticipantOrderStatusController::class)
        ->middleware('cache.headers:no_store;private')
        ->name('participant.order');
});

// ADR-0030: the trusted session-start command owns its own service transaction and
// rejects any pre-existing RLS context before touching SQL, so this one route
// deliberately excludes the `rls` middleware every other participant route uses.
// `dass21` stays in the route pattern (it must reach validation and produce a real
// 422 INVALID_ASSESSMENT_START_REQUEST, not a bare 404 from a route non-match); the
// FormRequest is what rejects it.
Route::middleware('participant.jwt')->group(function (): void {
    Route::post('/sessions/{testType}/start', StartParticipantSessionController::class)
        ->whereIn('testType', ['ist', 'papi', 'rmib', 'kraepelin', 'dass21'])
        ->name('participant.sessions.start');
});
