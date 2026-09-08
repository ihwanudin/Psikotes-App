<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionStateMachine;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionState;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionTransition;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssessmentSessionStateMachineTest extends TestCase
{
    public function test_it_exposes_the_frozen_states(): void
    {
        $this->assertSame(
            ['created', 'in_progress', 'submitted', 'scored', 'expired', 'void'],
            array_column(AssessmentSessionStatus::cases(), 'value'),
        );
    }

    #[DataProvider('allowedTransitions')]
    public function test_it_allows_only_frozen_transitions(
        AssessmentSessionStatus $from,
        AssessmentSessionStatus $to,
    ): void {
        $this->assertSame($to, (new AssessmentSessionStateMachine)->transition($from, $to));
    }

    /** @return iterable<string, array{AssessmentSessionStatus, AssessmentSessionStatus}> */
    public static function allowedTransitions(): iterable
    {
        yield 'created to in progress' => [AssessmentSessionStatus::Created, AssessmentSessionStatus::InProgress];
        yield 'created to void' => [AssessmentSessionStatus::Created, AssessmentSessionStatus::Voided];
        yield 'in progress to submitted' => [AssessmentSessionStatus::InProgress, AssessmentSessionStatus::Submitted];
        yield 'in progress to expired' => [AssessmentSessionStatus::InProgress, AssessmentSessionStatus::Expired];
        yield 'in progress to void' => [AssessmentSessionStatus::InProgress, AssessmentSessionStatus::Voided];
        yield 'submitted to scored' => [AssessmentSessionStatus::Submitted, AssessmentSessionStatus::Scored];
        yield 'submitted to recovery void' => [AssessmentSessionStatus::Submitted, AssessmentSessionStatus::Voided];
        yield 'expired to administrative void' => [AssessmentSessionStatus::Expired, AssessmentSessionStatus::Voided];
    }

    #[DataProvider('invalidTransitions')]
    public function test_it_rejects_transitions_outside_the_frozen_graph(
        AssessmentSessionStatus $from,
        AssessmentSessionStatus $to,
    ): void {
        $this->expectException(InvalidAssessmentSessionTransition::class);

        (new AssessmentSessionStateMachine)->transition($from, $to);
    }

    /** @return iterable<string, array{AssessmentSessionStatus, AssessmentSessionStatus}> */
    public static function invalidTransitions(): iterable
    {
        yield 'created cannot submit' => [AssessmentSessionStatus::Created, AssessmentSessionStatus::Submitted];
        yield 'submitted cannot expire' => [AssessmentSessionStatus::Submitted, AssessmentSessionStatus::Expired];
        yield 'scored is terminal' => [AssessmentSessionStatus::Scored, AssessmentSessionStatus::Voided];
        yield 'void is terminal' => [AssessmentSessionStatus::Voided, AssessmentSessionStatus::Created];
    }

    public function test_start_sets_a_deterministic_deadline_once(): void
    {
        $now = new DateTimeImmutable('2026-09-08T03:00:00.000000Z');

        $decision = (new AssessmentSessionStateMachine)->start(
            AssessmentSessionStatus::Created,
            null,
            null,
            $now,
            300,
        );

        $this->assertSame(AssessmentSessionStatus::InProgress, $decision->status);
        $this->assertSame($now, $decision->startedAt);
        $this->assertEquals(new DateTimeImmutable('2026-09-08T03:05:00.000000Z'), $decision->endsAt);
    }

    public function test_start_replay_preserves_the_original_deadline(): void
    {
        $startedAt = new DateTimeImmutable('2026-09-08T03:00:00.000000Z');
        $endsAt = new DateTimeImmutable('2026-09-08T03:05:00.000000Z');

        $decision = (new AssessmentSessionStateMachine)->start(
            AssessmentSessionStatus::InProgress,
            $startedAt,
            $endsAt,
            new DateTimeImmutable('2026-09-08T03:02:00.000000Z'),
            900,
        );

        $this->assertSame($startedAt, $decision->startedAt);
        $this->assertSame($endsAt, $decision->endsAt);
    }

    public function test_start_replay_fails_closed_when_persisted_timing_is_incomplete(): void
    {
        $this->expectException(InvalidAssessmentSessionState::class);

        (new AssessmentSessionStateMachine)->start(
            AssessmentSessionStatus::InProgress,
            new DateTimeImmutable('2026-09-08T03:00:00.000000Z'),
            null,
            new DateTimeImmutable('2026-09-08T03:02:00.000000Z'),
            300,
        );
    }

    public function test_submit_replay_is_idempotent_for_sealed_or_scored_sessions(): void
    {
        $machine = new AssessmentSessionStateMachine;

        $this->assertSame(AssessmentSessionStatus::Submitted, $machine->submit(AssessmentSessionStatus::Submitted));
        $this->assertSame(AssessmentSessionStatus::Scored, $machine->submit(AssessmentSessionStatus::Scored));
    }
}
