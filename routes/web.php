<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AssessmentParticipantExportController;
use App\Http\Controllers\Admin\IdentityEvidenceAccessController;
use App\Http\Controllers\Admin\ManualPaymentProofAccessController;
use App\Http\Controllers\AdminPasswordSetupController;
use App\Http\Controllers\AssessmentInvitationController;
use App\Http\Controllers\BilingualNarrativeController;
use App\Http\Controllers\CheckoutPaymentController;
use App\Http\Controllers\CheckoutSessionController;
use App\Http\Controllers\EligibilityDecisionController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\IdentityEvidenceUploadController;
use App\Http\Controllers\IntegratedCheckoutConfirmationController;
use App\Http\Controllers\ManualPaymentProofUploadController;
use App\Http\Controllers\NarrativeClusterEditController;
use App\Http\Controllers\ParticipantRegistrationController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\RegistrationOrderStatusController;
use App\Http\Controllers\ReportSigningController;
use App\Http\Controllers\ReviewInputController;
use App\Http\Controllers\SelectionLaunchController;
use App\Http\Controllers\ServeIstAssetController;
use App\Http\Controllers\XenditWebhookController;
use App\Http\Middleware\ApplyRlsContext;
use App\Http\Middleware\AuthenticateCheckoutSession;
use App\Http\Middleware\PreventCheckoutResponseCaching;
use App\Http\Middleware\ProtectCheckoutSessionHttpBoundary;
use App\Http\Middleware\VerifyCheckoutPaymentJsonMutation;
use App\Http\Middleware\VerifyCheckoutSessionJsonMutation;
use App\Http\Middleware\VerifyCheckoutSessionMutation;
use App\Services\Integrations\CheckoutSessionHttpContract;
use Filament\Http\Middleware\Authenticate as AuthenticateFilament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

RateLimiter::for(CheckoutSessionHttpContract::LIMITER,
    fn (Request $request) => app(CheckoutSessionHttpContract::class)->rateLimit($request));

$checkoutBoundary = [
    ProtectCheckoutSessionHttpBoundary::class,
    'throttle:'.CheckoutSessionHttpContract::LIMITER,
];
Route::post('/checkout/session', [CheckoutSessionController::class, 'exchange'])
    ->withoutMiddleware('web')->middleware($checkoutBoundary)->name('checkout.session.exchange');
Route::get('/checkout', [CheckoutSessionController::class, 'summary'])
    ->withoutMiddleware('web')->middleware($checkoutBoundary)->name('checkout.summary');
Route::post('/checkout/logout', [CheckoutSessionController::class, 'logout'])
    ->withoutMiddleware('web')->middleware([...$checkoutBoundary,
        AuthenticateCheckoutSession::class, VerifyCheckoutSessionMutation::class])->name('checkout.logout');
Route::get('/checkout/unavailable', [CheckoutSessionController::class, 'unavailable'])
    ->withoutMiddleware('web')->middleware($checkoutBoundary)->name('checkout.unavailable');
Route::post('/checkout/confirm', IntegratedCheckoutConfirmationController::class)
    ->withoutMiddleware('web')->middleware([...$checkoutBoundary,
        AuthenticateCheckoutSession::class, VerifyCheckoutSessionJsonMutation::class])->name('checkout.confirm');
Route::post('/checkout/payment', CheckoutPaymentController::class)
    ->withoutMiddleware('web')->middleware([
        PreventCheckoutResponseCaching::class,
        ProtectCheckoutSessionHttpBoundary::class,
        'throttle:'.CheckoutSessionHttpContract::LIMITER,
        VerifyCheckoutPaymentJsonMutation::class,
        AuthenticateCheckoutSession::class,
    ])->name('checkout.payment');

Route::get('/health', HealthCheckController::class)->withoutMiddleware('web')->name('health');

// Replaces the framework's own auto-registered local-disk signed-URL route
// for ist-assets ('serve' => false in config/filesystems.php) -- see
// ServeIstAssetController's doc comment. No session/CSRF: a valid signature
// is the whole auth story here, same as the framework's own equivalent
// route would have been.
Route::get('/private-ist-assets/{path}', ServeIstAssetController::class)
    ->where('path', '.*')
    ->withoutMiddleware('web')
    ->name('storage.ist-assets');

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

// Admin account lifecycle (2026-09-22, Lead sign-off): the new admin has no
// session yet, so this page (and its consume endpoint) must stay
// unauthenticated -- the one-time token itself is the whole auth story,
// same as the assessment-invitation flow above.
Route::get('/admin-password-setup/{publicId}', [AdminPasswordSetupController::class, 'show'])
    ->whereUlid('publicId')
    ->middleware('throttle:30,1')
    ->name('admin.password-setup.show');
Route::post('/admin-password-setup/{publicId}/consume', [AdminPasswordSetupController::class, 'consume'])
    ->whereUlid('publicId')
    ->middleware('throttle:10,1')
    ->name('admin.password-setup.consume');

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
    ->middleware(['auth:admin', 'admin.not-disabled', 'throttle:identity-evidence-access'])
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

Route::post('/admin/assessment-cases/{case}/eligibility-decisions', [EligibilityDecisionController::class, 'store'])
    ->whereUlid('case')
    ->middleware([
        'panel:admin',
        AuthenticateFilament::class,
        ApplyRlsContext::class,
        'throttle:eligibility-decision-access',
    ])
    ->name('admin.assessment-cases.eligibility-decisions.store');

Route::get('/admin/assessment-cases/{case}/eligibility-decisions', [EligibilityDecisionController::class, 'show'])
    ->whereUlid('case')
    ->middleware([
        'panel:admin',
        AuthenticateFilament::class,
        ApplyRlsContext::class,
        'throttle:eligibility-decision-access',
    ])
    ->name('admin.assessment-cases.eligibility-decisions.show');

Route::post('/admin/assessment-cases/{case}/bilingual-narratives', [BilingualNarrativeController::class, 'store'])
    ->whereUlid('case')
    ->middleware([
        'panel:admin',
        AuthenticateFilament::class,
        ApplyRlsContext::class,
        'throttle:bilingual-narrative-access',
    ])
    ->name('admin.assessment-cases.bilingual-narratives.store');

Route::get('/admin/assessment-cases/{case}/bilingual-narratives', [BilingualNarrativeController::class, 'show'])
    ->whereUlid('case')
    ->middleware([
        'panel:admin',
        AuthenticateFilament::class,
        ApplyRlsContext::class,
        'throttle:bilingual-narrative-access',
    ])
    ->name('admin.assessment-cases.bilingual-narratives.show');

Route::get('/admin/assessment-cases/{case}/narrative-cluster-edits', [NarrativeClusterEditController::class, 'show'])
    ->whereUlid('case')
    ->middleware([
        'panel:admin',
        AuthenticateFilament::class,
        ApplyRlsContext::class,
        'throttle:narrative-cluster-edit-access',
    ])
    ->name('admin.assessment-cases.narrative-cluster-edits.show');

Route::put('/admin/assessment-cases/{case}/narrative-cluster-edits/{cluster}', [NarrativeClusterEditController::class, 'update'])
    ->whereUlid('case')
    ->middleware([
        'panel:admin',
        AuthenticateFilament::class,
        ApplyRlsContext::class,
        'throttle:narrative-cluster-edit-access',
    ])
    ->name('admin.assessment-cases.narrative-cluster-edits.update');

Route::get('/admin/assessment-cases/{case}/review-input', ReviewInputController::class)
    ->whereUlid('case')
    ->middleware([
        'panel:admin',
        AuthenticateFilament::class,
        ApplyRlsContext::class,
        'throttle:review-input-access',
    ])
    ->name('admin.assessment-cases.review-input');

Route::post('/admin/assessment-cases/{case}/signing', [ReportSigningController::class, 'sign'])
    ->whereUlid('case')
    ->middleware([
        'panel:admin',
        AuthenticateFilament::class,
        ApplyRlsContext::class,
        'throttle:report-signing-access',
    ])
    ->name('admin.assessment-cases.signing.store');

Route::get('/admin/assessment-cases/{case}/signing', [ReportSigningController::class, 'show'])
    ->whereUlid('case')
    ->middleware([
        'panel:admin',
        AuthenticateFilament::class,
        ApplyRlsContext::class,
        'throttle:report-signing-access',
    ])
    ->name('admin.assessment-cases.signing.show');

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
