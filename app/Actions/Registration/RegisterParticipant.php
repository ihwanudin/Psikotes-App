<?php

declare(strict_types=1);

namespace App\Actions\Registration;

use App\Models\ConsentRecord;
use App\Models\Participant;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Referral\ReferralAttribution;
use Illuminate\Database\QueryException;

final readonly class RegisterParticipant
{
    public function __construct(
        private RlsContextRunner $runner,
        private ReferralAttribution $referrals,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input, string $registrationToken, ?string $referralCookie): Participant
    {
        try {
            return $this->runner->run(
                new RlsContext('service'),
                fn (): Participant => $this->createOnce($input, $registrationToken, $referralCookie),
            );
        } catch (QueryException $exception) {
            $participant = $this->runner->run(
                new RlsContext('service'),
                fn (): ?Participant => Participant::query()
                    ->where('registration_token', $registrationToken)
                    ->first(),
            );

            return $participant ?? throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createOnce(array $input, string $registrationToken, ?string $referralCookie): Participant
    {
        $existing = Participant::query()
            ->where('registration_token', $registrationToken)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $assignment = $this->referrals->assignmentFromCookie($referralCookie);
        $participant = new Participant;
        $participant->forceFill([
            'registration_token' => $registrationToken,
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
