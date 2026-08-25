<?php

declare(strict_types=1);

use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\ParticipantRegistrationController;
use App\Http\Controllers\ReferralController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)->name('health');

Route::get('/r/{refCode}', ReferralController::class)
    ->where('refCode', '[A-Za-z0-9_-]{1,64}')
    ->name('referral.resolve');

Route::get('/register', [ParticipantRegistrationController::class, 'create'])
    ->name('register');
Route::post('/registrations', [ParticipantRegistrationController::class, 'store'])
    ->middleware('throttle:registrations')
    ->name('registrations.store');
Route::get('/registration/received', [ParticipantRegistrationController::class, 'received'])
    ->name('registration.received');

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
