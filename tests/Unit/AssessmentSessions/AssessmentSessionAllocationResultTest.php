<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Actions\AssessmentSessions\AssessmentSessionAllocationResult;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssessmentSessionAllocationResultTest extends TestCase
{
    public function test_it_exposes_only_the_complete_public_active_session_contract(): void
    {
        $definition = self::definition();
        $result = new AssessmentSessionAllocationResult(
            sessionId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            status: AssessmentSessionStatus::InProgress,
            attemptNo: 1,
            answersRevision: 0,
            startedAt: new DateTimeImmutable('2026-09-10T12:00:00.123456+07:00'),
            endsAt: new DateTimeImmutable('2026-09-10T12:01:00.123456+07:00'),
            serverTime: new DateTimeImmutable('2026-09-10T12:00:01.623456+07:00'),
            definition: $definition,
            replayed: false,
        );

        $this->assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $result->sessionId);
        $this->assertSame(GenericAssessmentInstrument::Ist, $result->instrument);
        $this->assertSame(AssessmentSessionStatus::InProgress, $result->status);
        $this->assertSame(1, $result->attemptNo);
        $this->assertSame(0, $result->answersRevision);
        $this->assertSame('2026-09-10 05:00:00.123456+00:00', $result->startedAt->format('Y-m-d H:i:s.uP'));
        $this->assertSame('2026-09-10 05:01:00.123456+00:00', $result->endsAt->format('Y-m-d H:i:s.uP'));
        $this->assertSame($result->endsAt, $result->writeDeadline);
        $this->assertSame('2026-09-10 05:00:01.623456+00:00', $result->serverTime->format('Y-m-d H:i:s.uP'));
        $this->assertSame(59, $result->remainingSeconds);
        $this->assertSame(60, $result->durationSeconds);
        $this->assertSame($definition, $result->definition);
        $this->assertFalse($result->replayed);
    }

    public function test_replay_preserves_the_exact_public_identity_timestamps_and_definition_snapshot(): void
    {
        $definition = self::definition();
        $startedAt = new DateTimeImmutable('2026-09-10T05:00:00.123456Z');
        $endsAt = new DateTimeImmutable('2026-09-10T05:01:00.123456Z');
        $result = new AssessmentSessionAllocationResult(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            AssessmentSessionStatus::InProgress,
            1,
            3,
            $startedAt,
            $endsAt,
            new DateTimeImmutable('2026-09-10T05:00:30.123456Z'),
            $definition,
            true,
        );

        $this->assertTrue($result->replayed);
        $this->assertSame($startedAt->format('U.u'), $result->startedAt->format('U.u'));
        $this->assertSame($endsAt->format('U.u'), $result->endsAt->format('U.u'));
        $this->assertSame(30, $result->remainingSeconds);
        $this->assertSame($definition->toArray(), $result->definition->toArray());
        $reflection = new ReflectionClass($result);
        $this->assertFalse($reflection->hasProperty('grantId'));
        $this->assertFalse($reflection->hasProperty('participantId'));
        $this->assertFalse($reflection->hasProperty('assessmentCaseId'));
    }

    /** @param Closure(): AssessmentSessionAllocationResult $factory */
    #[DataProvider('invalidResults')]
    public function test_it_rejects_inconsistent_public_session_state(Closure $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }

    /** @return iterable<string, array{Closure(): AssessmentSessionAllocationResult}> */
    public static function invalidResults(): iterable
    {
        yield 'invalid public ULID' => [static fn (): AssessmentSessionAllocationResult => self::makeResult(sessionId: 'database-id')];
        yield 'non-active status' => [static fn (): AssessmentSessionAllocationResult => self::makeResult(status: AssessmentSessionStatus::Created)];
        yield 'invalid attempt number' => [static fn (): AssessmentSessionAllocationResult => self::makeResult(attemptNo: 0)];
        yield 'invalid answers revision' => [static fn (): AssessmentSessionAllocationResult => self::makeResult(answersRevision: -1)];
        yield 'duration differs from definition' => [static fn (): AssessmentSessionAllocationResult => self::makeResult(
            endsAt: new DateTimeImmutable('2026-09-10T05:02:00.123456Z'),
        )];
        yield 'server time precedes start' => [static fn (): AssessmentSessionAllocationResult => self::makeResult(
            serverTime: new DateTimeImmutable('2026-09-10T04:59:59.123456Z'),
        )];
        yield 'server time exceeds deadline' => [static fn (): AssessmentSessionAllocationResult => self::makeResult(
            serverTime: new DateTimeImmutable('2026-09-10T05:01:00.123457Z'),
        )];
    }

    public function test_result_is_an_immutable_value_object(): void
    {
        $reflection = new ReflectionClass(self::makeResult());

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    private static function makeResult(
        string $sessionId = '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        AssessmentSessionStatus $status = AssessmentSessionStatus::InProgress,
        int $attemptNo = 1,
        int $answersRevision = 0,
        ?DateTimeImmutable $endsAt = null,
        ?DateTimeImmutable $serverTime = null,
    ): AssessmentSessionAllocationResult {
        return new AssessmentSessionAllocationResult(
            $sessionId,
            $status,
            $attemptNo,
            $answersRevision,
            new DateTimeImmutable('2026-09-10T05:00:00.123456Z'),
            $endsAt ?? new DateTimeImmutable('2026-09-10T05:01:00.123456Z'),
            $serverTime ?? new DateTimeImmutable('2026-09-10T05:00:00.123456Z'),
            self::definition(),
            false,
        );
    }

    private static function definition(): SessionDefinition
    {
        $input = [
            'instrument' => 'ist',
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-test-fixture',
            'checksum' => '',
            'total_duration_seconds' => 60,
            'subtests' => [[
                'code' => 'SYNTHETIC',
                'duration_seconds' => 60,
                'item_count' => 1,
            ]],
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => null,
        ];
        $input['checksum'] = SessionDefinition::checksumFor($input);

        return SessionDefinition::fromArray($input);
    }
}
