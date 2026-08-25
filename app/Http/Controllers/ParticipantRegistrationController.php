<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Registration\RegisterParticipant;
use App\Http\Requests\StoreParticipantRegistrationRequest;
use App\Models\TestPackage;
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
        $packages = TestPackage::query()
            ->availableForRegistration()
            ->with('items:id,package_id,test_type,sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('registration/create', [
            'assignedBranch' => [
                'name' => $assignment->branch->name,
                'source' => $assignment->source,
            ],
            'registrationToken' => $token,
            'packages' => $packages->map(fn (TestPackage $package): array => [
                'id' => $package->id,
                'code' => $package->code,
                'name' => $package->name,
                'description' => $package->description,
                'amount' => $package->amount,
                'currency' => $package->currency,
                'testTypes' => $package->items->pluck('test_type')->values()->all(),
            ]),
            'packageConfigurationPending' => $packages->isEmpty(),
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
        $packageId = $request->integer('package_id');
        unset(
            $validated['_registration_token'],
            $validated['package_id'],
            $validated['consent_psychotest'],
        );
        $cookieName = (string) config('referral.cookie_name', 'psikotes_referral');
        $cookie = $request->cookie($cookieName);

        $register->handle(
            $validated,
            $packageId,
            $token,
            is_string($cookie) ? $cookie : null,
        );

        return redirect()->route('registration.received');
    }

    public function received(): Response
    {
        return Inertia::render('registration/received');
    }
}
