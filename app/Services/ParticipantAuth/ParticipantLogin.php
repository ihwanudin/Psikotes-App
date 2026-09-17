<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Models\Participant;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\Exceptions\InvalidParticipantCredentials;

final readonly class ParticipantLogin
{
    public function __construct(
        private RlsContextRunner $runner,
        private ParticipantLoginThrottle $throttle,
        private ParticipantJwt $jwt,
    ) {}

    public function attempt(string $testNumber, string $birthDate): string
    {
        $normalizedNumber = strtoupper(trim($testNumber));
        $this->throttle->ensureNotLocked($normalizedNumber);

        $participant = $this->runner->run(
            new RlsContext('service'),
            fn (): ?Participant => Participant::query()
                ->where('test_number', $normalizedNumber)
                ->first(),
        );
        $storedBirthDate = $participant?->birth_date?->format('Y-m-d') ?? '0000-00-00';

        if ($participant === null || ! hash_equals($storedBirthDate, $birthDate)) {
            $this->throttle->recordFailure($normalizedNumber);

            throw new InvalidParticipantCredentials;
        }

        $this->throttle->clear($normalizedNumber);

        return $this->jwt->issue($participant->id, $participant->branch_id);
    }
}
