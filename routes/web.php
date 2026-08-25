<?php

declare(strict_types=1);

use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\ReferralController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)->name('health');

Route::get('/r/{refCode}', ReferralController::class)
    ->where('refCode', '[A-Za-z0-9_-]{1,64}')
    ->name('referral.resolve');

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
