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
    Route::post('/sessions/{testType}/start', StartParticipantSessionController::class)
        ->whereIn('testType', ['ist', 'papi', 'rmib', 'kraepelin', 'dass21'])
        ->name('participant.sessions.start');
});
