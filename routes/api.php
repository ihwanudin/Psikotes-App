<?php

declare(strict_types=1);

use App\Http\Controllers\ParticipantEntitlementController;
use App\Http\Controllers\ParticipantLoginController;
use App\Http\Controllers\ParticipantOrderStatusController;
use App\Http\Controllers\ParticipantProfileController;
use App\Http\Controllers\StartParticipantSessionController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/participant/login', ParticipantLoginController::class)
    ->middleware('throttle:participant-login')
    ->name('participant.login');

Route::middleware(['participant.jwt', 'rls'])->group(function (): void {
    Route::get('/me', ParticipantProfileController::class)->name('participant.me');
    Route::get('/me/entitlements', ParticipantEntitlementController::class)
        ->name('participant.entitlements');
    Route::get('/me/order', ParticipantOrderStatusController::class)
        ->name('participant.order');
    Route::post('/sessions/{testType}/start', StartParticipantSessionController::class)
        ->whereIn('testType', ['ist', 'papi', 'rmib', 'kraepelin', 'dass21'])
        ->name('participant.sessions.start');
});
