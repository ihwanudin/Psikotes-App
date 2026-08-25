<?php

declare(strict_types=1);

namespace App\Actions\Registration;

use App\Models\ConsentRecord;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Referral\ReferralAttribution;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final readonly class RegisterParticipant
{
    public function __construct(
        private RlsContextRunner $runner,
        private ReferralAttribution $referrals,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(
        array $input,
        int $packageId,
        string $registrationToken,
        ?string $referralCookie,
    ): Participant {
        $payloadHash = $this->payloadHash([...$input, 'package_id' => $packageId]);

        try {
            return $this->runner->run(
                new RlsContext('service'),
                fn (): Participant => $this->createOnce(
                    $input,
                    $packageId,
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
            ->availableForRegistration()
            ->sharedLock()
            ->find($packageId);

        if ($package === null) {
            throw ValidationException::withMessages([
                'package_id' => 'Paket tidak tersedia untuk pendaftaran.',
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
            'full_name' => $input['full_name'],
            'gender' => $input['gender'],
            'birth_date' => $input['birth_date'],
            'education_level' => $input['education_level'],
            'intended_field' => $input['intended_field'],
            'phone' => preg_replace('/[^0-9+]/', '', (string) $input['phone']),
            'email' => $input['email'] ?? null,
        ])->save();

        $this->recordConsent($participant, ConsentDocument::for('psychotest'), true);
        $this->recordConsent($participant, ConsentDocument::for('dass'), (bool) $input['consent_dass']);

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
