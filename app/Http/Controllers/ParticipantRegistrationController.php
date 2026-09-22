<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Registration\RegisterParticipant;
use App\Http\Requests\StoreParticipantRegistrationRequest;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\CreateRegistrationInvoice;
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
        $packages = $runner->run(
            new RlsContext('service'),
            fn () => TestPackage::query()
                ->availableForRegistration()
                ->with('items:id,package_id,test_type,sort_order')
                ->orderBy('name')
                ->get(),
        );
        $paymentMethods = $runner->run(
            new RlsContext('service'),
            fn () => PaymentMethod::query()
                ->active()
                ->orderBy('display_name')
                ->get(['code', 'display_name']),
        );

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
                'consultationAmount' => $package->consultation_amount,
                'currency' => $package->currency,
                'testTypes' => $package->items->pluck('test_type')->values()->all(),
            ]),
            'packageConfigurationPending' => $packages->isEmpty(),
            'paymentMethods' => $paymentMethods->map(fn (PaymentMethod $method): array => [
                'code' => $method->code,
                'displayName' => $method->display_name,
            ]),
            'paymentConfigurationPending' => $paymentMethods->isEmpty(),
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
        CreateRegistrationInvoice $createInvoice,
    ): RedirectResponse {
        $validated = $request->validated();
        $token = (string) $validated['_registration_token'];
        $packageId = $request->integer('package_id');
        $paymentMethodCode = isset($validated['payment_method_code'])
            ? (string) $validated['payment_method_code']
            : null;
        unset(
            $validated['_registration_token'],
            $validated['package_id'],
            $validated['payment_method_code'],
            $validated['consent_psychotest'],
        );
        $cookieName = (string) config('referral.cookie_name', 'psikotes_referral');
        $cookie = $request->cookie($cookieName);

        $participant = $register->handle(
            $validated,
            $packageId,
            $paymentMethodCode,
            $token,
            is_string($cookie) ? $cookie : null,
        );

        $request->session()->put([
            'registration.participant_id' => $participant->id,
            'registration.evidence_authorized_until' => now()
                ->addHours((int) config('identity.upload_session_hours', 2))
                ->getTimestamp(),
        ]);

        $invoice = $createInvoice->handle($participant);

        if ($invoice !== null) {
            return redirect()->away($invoice->paymentUrl);
        }

        return redirect()->route('registration.received');
    }

    public function received(Request $request, RlsContextRunner $runner): Response
    {
        $participantId = $request->session()->get('registration.participant_id');
        $authorizedUntil = $request->session()->get('registration.evidence_authorized_until');
        $isAuthorized = is_numeric($participantId)
            && is_numeric($authorizedUntil)
            && (int) $authorizedUntil >= now()->getTimestamp();
        $state = [
            'authorized' => false,
            'complete' => false,
            'outcome' => null,
            'manualStatus' => null,
        ];
        $manualPayment = [
            'required' => false,
            'proofUploaded' => false,
            'status' => null,
            'amount' => null,
            'currency' => null,
            'rejectionReason' => null,
        ];

        if ($isAuthorized) {
            $participant = $runner->run(
                new RlsContext('service'),
                fn (): ?Participant => Participant::query()
                    ->with([
                        'identityEvidence:id,participant_id,type',
                        'identityVerification',
                        'orders' => fn ($query) => $query
                            ->with('paymentMethod')
                            ->latest('id'),
                    ])
                    ->find((int) $participantId),
            );

            if ($participant !== null) {
                $types = $participant->identityEvidence->pluck('type');
                $state = [
                    'authorized' => true,
                    'complete' => $types->contains('identity_document')
                        && $types->contains('initial_selfie'),
                    'outcome' => $participant->identityVerification?->outcome,
                    'manualStatus' => $participant->identityVerification?->manual_status,
                ];
                $manualOrder = $participant->orders->first(
                    fn ($order): bool => $order->payment_method_id !== null
                        && $order->paymentMethod->code === 'manual_transfer',
                );

                if ($manualOrder !== null) {
                    $manualPayment = [
                        'required' => true,
                        'proofUploaded' => is_string($manualOrder->proof_object_key),
                        'status' => $manualOrder->status->value,
                        'amount' => $manualOrder->amount,
                        'currency' => $manualOrder->currency,
                        'rejectionReason' => $manualOrder->rejection_reason,
                    ];
                }
            }
        }

        return Inertia::render('registration/received', [
            'identityEvidence' => $state,
            'manualPayment' => $manualPayment,
            'status' => $request->session()->get('status'),
        ]);
    }
}
