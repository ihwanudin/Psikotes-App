<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AssessmentParticipantExportController;
use App\Http\Controllers\Admin\IdentityEvidenceAccessController;
use App\Http\Controllers\Admin\ManualPaymentProofAccessController;
use App\Http\Controllers\AssessmentInvitationController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\IdentityEvidenceUploadController;
use App\Http\Controllers\ManualPaymentProofUploadController;
use App\Http\Controllers\ParticipantRegistrationController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\RegistrationOrderStatusController;
use App\Http\Controllers\SelectionLaunchController;
use App\Http\Controllers\XenditWebhookController;
use App\Http\Middleware\ApplyRlsContext;
use Filament\Http\Middleware\Authenticate as AuthenticateFilament;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)->name('health');

Route::get('/selection/launch', SelectionLaunchController::class)
    ->middleware('throttle:30,1')
    ->name('selection.launch');

Route::get('/assessment/invitations/{publicId}', [AssessmentInvitationController::class, 'show'])
    ->whereUlid('publicId')
    ->middleware('throttle:30,1')
    ->name('assessment.invitations.show');
Route::post('/assessment/invitations/{publicId}/consume', [AssessmentInvitationController::class, 'consume'])
    ->whereUlid('publicId')
    ->middleware('throttle:10,1')
    ->name('assessment.invitations.consume');

Route::inertia('/participant/lobby', 'participant/lobby')
    ->name('participant.lobby');

Route::post('/webhooks/xendit', XenditWebhookController::class)
    ->name('webhooks.xendit');

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
Route::get('/registration/order-status', RegistrationOrderStatusController::class)
    ->middleware('cache.headers:no_store;private')
    ->name('registration.order-status');
Route::post('/registration/identity-evidence', IdentityEvidenceUploadController::class)
    ->middleware('throttle:identity-evidence-uploads')
    ->name('registration.identity-evidence.store');
Route::post('/registration/manual-payment-proof', ManualPaymentProofUploadController::class)
    ->middleware('throttle:manual-payment-proof-uploads')
    ->name('registration.manual-payment-proof.store');

Route::post('/admin/identity-evidence/{evidence}/temporary-url', IdentityEvidenceAccessController::class)
    ->whereUlid('evidence')
    ->middleware(['auth:admin', 'throttle:identity-evidence-access'])
    ->name('admin.identity-evidence.temporary-url');
Route::get('/admin/manual-payment-proofs/{order}/open', ManualPaymentProofAccessController::class)
    ->whereUlid('order')
    ->middleware([
        'panel:admin',
        AuthenticateFilament::class,
        ApplyRlsContext::class,
        'throttle:manual-payment-proof-access',
    ])
    ->name('admin.manual-payment-proofs.open');

Route::get('/admin/assessment-participants/export.csv', AssessmentParticipantExportController::class)
    ->middleware([
        'panel:admin',
        AuthenticateFilament::class,
        ApplyRlsContext::class,
        'throttle:30,1',
    ])
    ->name('admin.assessment-participants.export');

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
