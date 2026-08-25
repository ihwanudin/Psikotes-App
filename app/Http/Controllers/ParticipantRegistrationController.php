<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Registration\RegisterParticipant;
use App\Http\Requests\StoreParticipantRegistrationRequest;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Referral\ReferralAttribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class ParticipantRegistrationController extends Controller
{
    public function create(
        Request $request,
        ReferralAttribution $referrals,
        RlsContextRunner $runner,
    ): Response {
        $cookieName = (string) config('referral.cookie_name', 'psikotes_referral');
        $cookie = $request->cookie($cookieName);
        $assignment = $runner->run(
            new RlsContext('service'),
            fn () => $referrals->assignmentFromCookie(is_string($cookie) ? $cookie : null),
        );
        $token = (string) Str::uuid();
        $request->session()->put('registration.token', $token);

        return Inertia::render('registration/create', [
            'assignedBranch' => [
                'name' => $assignment->branch->name,
                'source' => $assignment->source,
            ],
            'registrationToken' => $token,
            'consents' => [
                'psychotest' => ConsentDocument::for('psychotest')->toPublicArray(),
                'dass' => ConsentDocument::for('dass')->toPublicArray(),
                'legalReviewPending' => (bool) config('consent.legal_review_pending', true),
            ],
        ]);
    }

    public function store(
        StoreParticipantRegistrationRequest $request,
        RegisterParticipant $register,
    ): RedirectResponse {
        $validated = $request->validated();
        $token = (string) $validated['_registration_token'];
        unset($validated['_registration_token'], $validated['consent_psychotest']);
        $cookieName = (string) config('referral.cookie_name', 'psikotes_referral');
        $cookie = $request->cookie($cookieName);

        $register->handle($validated, $token, is_string($cookie) ? $cookie : null);

        return redirect()->route('registration.received');
    }

    public function received(): Response
    {
        return Inertia::render('registration/received');
    }
}
