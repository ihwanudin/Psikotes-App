<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionDeadlinePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionErrorCode;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionState;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssessmentSessionDeadlinePolicyTest extends TestCase
{
    public function test_receipt_exactly_at_ends_at_is_accepted(): void
    {
        $endsAt = new DateTimeImmutable('2026-09-08T03:05:00.000000Z');

        $decision = (new AssessmentSessionDeadlinePolicy)->evaluateAnswerWrite(
            AssessmentSessionStatus::InProgress,
            $endsAt,
            $endsAt,
        );

        $this->assertTrue($decision->accepted);
        $this->assertSame(AssessmentSessionStatus::InProgress, $decision->status);
        $this->assertNull($decision->errorCode);
    }

    public function test_receipt_after_ends_at_is_rejected_and_expires_the_session(): void
    {
        $decision = (new AssessmentSessionDeadlinePolicy)->evaluateAnswerWrite(
            AssessmentSessionStatus::InProgress,
            new DateTimeImmutable('2026-09-08T03:05:00.000000Z'),
            new DateTimeImmutable('2026-09-08T03:05:00.000001Z'),
        );

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionStatus::Expired, $decision->status);
        $this->assertSame(AssessmentSessionErrorCode::DeadlineExceeded, $decision->errorCode);
    }

    #[DataProvider('closedStatuses')]
    public function test_sealed_and_terminal_states_never_accept_answer_writes(
        AssessmentSessionStatus $status,
    ): void {
        $decision = (new AssessmentSessionDeadlinePolicy)->evaluateAnswerWrite(
            $status,
            new DateTimeImmutable('2026-09-08T03:05:00.000000Z'),
            new DateTimeImmutable('2026-09-08T03:01:00.000000Z'),
        );

        $this->assertFalse($decision->accepted);
        $this->assertSame($status, $decision->status);
        $this->assertSame(AssessmentSessionErrorCode::SessionClosed, $decision->errorCode);
    }

    /** @return iterable<string, array{AssessmentSessionStatus}> */
    public static function closedStatuses(): iterable
    {
        yield 'submitted' => [AssessmentSessionStatus::Submitted];
        yield 'scored' => [AssessmentSessionStatus::Scored];
        yield 'expired' => [AssessmentSessionStatus::Expired];
        yield 'void' => [AssessmentSessionStatus::Voided];
    }

    public function test_created_session_rejects_answers_as_not_started(): void
    {
        $decision = (new AssessmentSessionDeadlinePolicy)->evaluateAnswerWrite(
            AssessmentSessionStatus::Created,
            null,
            new DateTimeImmutable('2026-09-08T03:01:00.000000Z'),
        );

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionErrorCode::SessionNotStarted, $decision->errorCode);
    }

    public function test_in_progress_session_without_deadline_fails_closed(): void
    {
        $this->expectException(InvalidAssessmentSessionState::class);

        (new AssessmentSessionDeadlinePolicy)->evaluateAnswerWrite(
            AssessmentSessionStatus::InProgress,
            null,
            new DateTimeImmutable('2026-09-08T03:01:00.000000Z'),
        );
    }
}
