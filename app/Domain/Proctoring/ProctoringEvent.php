<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

use InvalidArgumentException;

final readonly class ProctoringEvent
{
    public function __construct(
        public string $evidenceId,
        public ProctoringInstrument $instrument,
        public ProctoringEventKind $kind,
    ) {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,99}\z/D', $evidenceId)) {
            throw new InvalidArgumentException('Proctoring evidence ID is invalid.');
        }
    }

    public function hasSamePayload(self $other): bool
    {
        return $this->evidenceId === $other->evidenceId
            && $this->instrument === $other->instrument
            && $this->kind === $other->kind;
    }
}
