<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Registration\ConsentDocument;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Internal captured inputs; the caller owns authority, database time and server timezone. */
final readonly class AssessmentPrerequisiteFrame
{
    public string $serverDate;

    public function __construct(
        public CarbonImmutable $asOf,
        string $serverTimezone,
        private ?ConsentDocument $psychotest,
        private ?ConsentDocument $dass = null,
    ) {
        foreach (['psychotest' => $psychotest, 'dass' => $dass] as $type => $document) {
            if ($document !== null && $document->type !== $type) {
                throw new InvalidArgumentException("Mismatched consent document [{$type}].");
            }
        }
        // Derive the calendar label from the instant; callers cannot supply a contradictory date.
        $this->serverDate = $asOf->setTimezone(new DateTimeZone($serverTimezone))->toDateString();
    }

    public function documentFor(string $type): ConsentDocument
    {
        $document = match ($type) {
            'psychotest' => $this->psychotest,
            'dass' => $this->dass,
            default => throw new InvalidArgumentException("Unsupported consent document [{$type}]."),
        };

        return $document ?? throw new InvalidArgumentException("Missing required consent document [{$type}].");
    }
}
