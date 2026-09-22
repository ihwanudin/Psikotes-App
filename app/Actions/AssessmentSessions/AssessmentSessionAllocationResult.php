<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class AssessmentSessionAllocationResult
{
    public string $sessionId;

    public GenericAssessmentInstrument $instrument;

    public AssessmentSessionStatus $status;

    public int $attemptNo;

    public int $answersRevision;

    public DateTimeImmutable $startedAt;

    public DateTimeImmutable $endsAt;

    public DateTimeImmutable $writeDeadline;

    public DateTimeImmutable $serverTime;

    public int $remainingSeconds;

    public int $durationSeconds;

    public SessionDefinition $definition;

    public bool $replayed;

    public function __construct(
        string $sessionId,
        AssessmentSessionStatus $status,
        int $attemptNo,
        int $answersRevision,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $endsAt,
        DateTimeImmutable $serverTime,
        SessionDefinition $definition,
        bool $replayed,
    ) {
        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $sessionId) !== 1) {
            throw new InvalidArgumentException('Assessment session public identity must be a canonical ULID.');
        }
        if ($status !== AssessmentSessionStatus::InProgress) {
            throw new InvalidArgumentException('Assessment session allocation result must be active.');
        }
        if ($attemptNo < 1 || $answersRevision < 0) {
            throw new InvalidArgumentException('Assessment session attempt and revision are invalid.');
        }

        $utc = new DateTimeZone('UTC');
        $startedAt = $startedAt->setTimezone($utc);
        $endsAt = $endsAt->setTimezone($utc);
        $serverTime = $serverTime->setTimezone($utc);
        // F2 timed-segments stage 3 (2026-09-22), revision 1's ends_at formula:
        // started_at + totalDurationSeconds + totalReadingCapSeconds. Always 0
        // reading cap today, so this is currently the same window as before.
        $expectedEndsAt = $startedAt->add(new DateInterval(
            'PT'.($definition->totalDurationSeconds + $definition->totalReadingCapSeconds).'S',
        ));

        if ($endsAt->format('U.u') !== $expectedEndsAt->format('U.u')) {
            throw new InvalidArgumentException('Assessment session duration must match its definition snapshot.');
        }
        if ($serverTime < $startedAt || $serverTime > $endsAt) {
            throw new InvalidArgumentException('Assessment session server time must be inside the active window.');
        }

        $this->sessionId = $sessionId;
        $this->instrument = $definition->instrument;
        $this->status = $status;
        $this->attemptNo = $attemptNo;
        $this->answersRevision = $answersRevision;
        $this->startedAt = $startedAt;
        $this->endsAt = $endsAt;
        $this->writeDeadline = $endsAt;
        $this->serverTime = $serverTime;
        $this->remainingSeconds = max(0, (int) ceil(
            (float) $endsAt->format('U.u') - (float) $serverTime->format('U.u'),
        ));
        $this->durationSeconds = $definition->totalDurationSeconds;
        $this->definition = $definition;
        $this->replayed = $replayed;
    }
}
