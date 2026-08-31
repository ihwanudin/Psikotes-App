<?php

declare(strict_types=1);

namespace App\Actions\Registration;

use App\Actions\Notifications\EnqueueParticipantActivation;
use App\Models\ConsentRecord;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Referral\ReferralAttribution;
use App\Services\TestNumber\MonthlyTestNumberIssuer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class RegisterParticipant
{
    public function __construct(
        private RlsContextRunner $runner,
        private ReferralAttribution $referrals,
        private MonthlyTestNumberIssuer $testNumbers,
        private EnqueueParticipantActivation $enqueueActivation,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(
        array $input,
        int $packageId,
        ?string $paymentMethodCode,
        string $registrationToken,
        ?string $referralCookie,
    ): Participant {
        $payloadHash = $this->payloadHash([
            ...$input,
            'package_id' => $packageId,
            'payment_method_code' => $paymentMethodCode,
        ]);

        try {
            return $this->runner->run(
                new RlsContext('service'),
                fn (): Participant => $this->createOnce(
                    $input,
                    $packageId,
                    $paymentMethodCode,
                    $registrationToken,
                    $payloadHash,
                    $referralCookie,
                ),
            );
        } catch (QueryException $exception) {
            $participant = $this->runner->run(
                new RlsContext('service'),
                fn (): ?Participant => Participant::query()
                    ->where('registration_token', $registrationToken)
                    ->first(),
            );

            if ($participant === null) {
                throw $exception;
            }

            $this->assertMatchingPayload($participant, $payloadHash);

            return $participant;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createOnce(
        array $input,
        int $packageId,
        ?string $paymentMethodCode,
        string $registrationToken,
        string $payloadHash,
        ?string $referralCookie,
    ): Participant {
        $existing = Participant::query()
            ->where('registration_token', $registrationToken)
            ->first();

        if ($existing !== null) {
            $this->assertMatchingPayload($existing, $payloadHash);

            return $existing;
        }

        $package = TestPackage::query()
            ->with('items')
            ->availableForRegistration()
            ->sharedLock()
            ->find($packageId);

        if ($package === null) {
            throw ValidationException::withMessages([
                'package_id' => 'Paket tidak tersedia untuk pendaftaran.',
            ]);
        }

        $consultationSelected = (bool) ($input['include_consultation'] ?? false);
        $consultationAmount = $consultationSelected ? $package->consultation_amount : 0;

        if ($consultationSelected && $consultationAmount === null) {
            throw ValidationException::withMessages([
                'include_consultation' => 'Konsultasi psikolog belum tersedia untuk paket ini.',
            ]);
        }

        $totalAmount = (int) $package->amount + (int) $consultationAmount;
        $paymentMethod = $totalAmount > 0
            ? PaymentMethod::query()
                ->active()
                ->sharedLock()
                ->where('code', $paymentMethodCode)
                ->first()
            : null;

        if ($totalAmount > 0 && $paymentMethod === null) {
            throw ValidationException::withMessages([
                'payment_method_code' => 'Metode pembayaran tidak tersedia.',
            ]);
        }

        $assignment = $this->referrals->assignmentFromCookie($referralCookie);
        $participant = new Participant;
        $participant->forceFill([
            'registration_token' => $registrationToken,
            'registration_payload_hash' => $payloadHash,
            'package_id' => $package->id,
            'branch_id' => $assignment->branch->id,
            'referral_branch_id' => $assignment->branch->id,
            'referral_source' => $assignment->source,
            'source_system' => 'DIRECT_PUBLIC',
            'attribution_source' => $assignment->branch->ref_code,
            'full_name' => $input['full_name'],
            'gender' => $input['gender'],
            'birth_date' => $input['birth_date'],
            'education_level' => $input['education_level'],
            'intended_field' => $input['intended_field'],
            'phone' => preg_replace('/[^0-9+]/', '', (string) $input['phone']),
            'email' => $input['email'] ?? null,
            'test_number' => $this->testNumbers->issue(),
        ])->save();

        $now = now();
        $isFree = $totalAmount === 0;
        $order = Order::query()->create([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant->id,
            'payment_method_id' => $paymentMethod?->id,
            'status' => $isFree ? 'paid' : 'pending',
            'amount' => $totalAmount,
            'currency' => $package->currency,
            'paid_at' => $isFree ? $now : null,
            'metadata' => [
                'pricing' => [
                    'package_code' => $package->code,
                    'package_amount' => (int) $package->amount,
                    'consultation_selected' => $consultationSelected,
                    'consultation_amount' => (int) $consultationAmount,
                ],
            ],
        ]);
        $participant->entitlements()->createMany(
            $package->items
                ->reject(fn ($item): bool => $item->test_type === 'dass21' && ! (bool) $input['consent_dass'])
                ->map(fn ($item): array => [
                    'order_id' => $order->id,
                    'test_type' => $item->test_type,
                    'status' => $isFree ? 'ready' : 'locked',
                    'ready_at' => $isFree ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
        );

        $this->recordConsent($participant, ConsentDocument::for('psychotest'), true);
        $this->recordConsent($participant, ConsentDocument::for('dass'), (bool) $input['consent_dass']);

        if ($isFree) {
            $this->enqueueActivation->handle($order);
        }

        return $participant;
    }

    /** @param array<string, mixed> $input */
    private function payloadHash(array $input): string
    {
        ksort($input);

        return hash_hmac(
            'sha256',
            json_encode($input, JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }

    private function assertMatchingPayload(Participant $participant, string $payloadHash): void
    {
        if (! is_string($participant->registration_payload_hash)
            || ! hash_equals($participant->registration_payload_hash, $payloadHash)) {
            throw ValidationException::withMessages([
                '_registration_token' => 'Token registrasi telah digunakan untuk data yang berbeda.',
            ]);
        }
    }

    private function recordConsent(Participant $participant, ConsentDocument $document, bool $accepted): void
    {
        ConsentRecord::query()->create([
            'participant_id' => $participant->id,
            'consent_type' => $document->type,
            'status' => $accepted ? 'accepted' : 'declined',
            'document_version' => $document->version,
            'document_hash' => $document->hash,
            'consented_at' => $accepted ? now() : null,
            'withdrawn_at' => null,
        ]);
    }
}
