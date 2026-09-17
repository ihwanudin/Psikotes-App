<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionErrorCode;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\AssessmentSessionSubmitPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssessmentSessionSubmitPolicyTest extends TestCase
{
    public function test_submit_exactly_at_ends_at_is_accepted_and_sealed(): void
    {
        $endsAt = new DateTimeImmutable('2026-09-08T03:05:00.000000Z');

        $decision = (new AssessmentSessionSubmitPolicy)->decide(
            AssessmentSessionStatus::InProgress,
            $endsAt,
            $endsAt,
        );

        $this->assertTrue($decision->accepted);
        $this->assertFalse($decision->replayed);
        $this->assertSame(AssessmentSessionStatus::Submitted, $decision->status);
        $this->assertNull($decision->errorCode);
    }

    public function test_submit_after_ends_at_is_rejected_and_expires_session(): void
    {
        $decision = (new AssessmentSessionSubmitPolicy)->decide(
            AssessmentSessionStatus::InProgress,
            new DateTimeImmutable('2026-09-08T03:05:00.000000Z'),
            new DateTimeImmutable('2026-09-08T03:05:00.000001Z'),
        );

        $this->assertFalse($decision->accepted);
        $this->assertFalse($decision->replayed);
        $this->assertSame(AssessmentSessionStatus::Expired, $decision->status);
        $this->assertSame(AssessmentSessionErrorCode::DeadlineExceeded, $decision->errorCode);
    }

    #[DataProvider('replayStates')]
    public function test_submitted_and_scored_submit_replays_are_idempotent(
        AssessmentSessionStatus $status,
    ): void {
        $decision = (new AssessmentSessionSubmitPolicy)->decide(
            $status,
            new DateTimeImmutable('2026-09-08T03:05:00.000000Z'),
            new DateTimeImmutable('2026-09-08T04:00:00.000000Z'),
        );

        $this->assertTrue($decision->accepted);
        $this->assertTrue($decision->replayed);
        $this->assertSame($status, $decision->status);
        $this->assertNull($decision->errorCode);
    }

    /** @return iterable<string, array{AssessmentSessionStatus}> */
    public static function replayStates(): iterable
    {
        yield 'submitted' => [AssessmentSessionStatus::Submitted];
        yield 'scored' => [AssessmentSessionStatus::Scored];
    }
}
