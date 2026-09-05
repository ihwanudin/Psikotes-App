<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Services\Integrations\GenericAssessmentResultProjector;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GenericAssessmentResultProjectorTest extends TestCase
{
    public function test_it_projects_one_canonical_payload_for_callback_and_poll_without_rounding_iq(): void
    {
        $projector = new GenericAssessmentResultProjector;
        $source = $this->source(iq: 98.75);

        $callback = $projector->project($source);
        $poll = $projector->project($source);

        $this->assertSame($callback, $poll);
        $this->assertSame(98.75, $callback['iq']);
        $this->assertSame('2026-09-05T03:15:30.123456Z', $callback['completedAt']);
        $this->assertNull($callback['revokedAt']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $callback['resultChecksum']);
        $this->assertSame([
            'assessmentAttemptId', 'iq', 'engineVersion', 'completedAt', 'finality',
            'revokedAt', 'resultVersion', 'resultChecksum',
        ], array_keys($callback));
    }

    public function test_iq_at_the_psychometric_contract_ceiling_is_accepted(): void
    {
        $projected = (new GenericAssessmentResultProjector)->project($this->source(iq: 300));

        $this->assertSame(300, $projected['iq']);
    }

    public function test_same_version_and_payload_is_an_exact_replay(): void
    {
        $projector = new GenericAssessmentResultProjector;
        $first = $projector->project($this->source());

        $this->assertSame($first, $projector->project($this->source(), $first));
    }

    public function test_correction_requires_the_next_version_and_a_changed_checksum(): void
    {
        $projector = new GenericAssessmentResultProjector;
        $first = $projector->project($this->source());
        $correction = $projector->project($this->source(iq: 101.25, resultVersion: 2), $first);

        $this->assertSame(2, $correction['resultVersion']);
        $this->assertNotSame($first['resultChecksum'], $correction['resultChecksum']);
    }

    public function test_next_version_without_a_semantic_change_is_rejected(): void
    {
        $projector = new GenericAssessmentResultProjector;
        $first = $projector->project($this->source());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ASSESSMENT_RESULT_VERSION_NO_CHANGE');

        $projector->project($this->source(resultVersion: 2), $first);
    }

    public function test_revocation_is_a_new_version_and_keeps_the_authoritative_iq_snapshot(): void
    {
        $projector = new GenericAssessmentResultProjector;
        $first = $projector->project($this->source());
        $revoked = $projector->project($this->source(
            resultVersion: 2,
            revokedAt: '2026-09-05T11:30:00+07:00',
        ), $first);

        $this->assertSame(99, $revoked['iq']);
        $this->assertSame('2026-09-05T04:30:00.000000Z', $revoked['revokedAt']);
        $this->assertNotSame($first['resultChecksum'], $revoked['resultChecksum']);
    }

    public function test_same_version_with_changed_content_is_rejected_as_a_conflict(): void
    {
        $projector = new GenericAssessmentResultProjector;
        $first = $projector->project($this->source());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ASSESSMENT_RESULT_VERSION_CONFLICT');

        $projector->project($this->source(iq: 100), $first);
    }

    public function test_stale_skipped_or_cross_attempt_versions_are_rejected(): void
    {
        $projector = new GenericAssessmentResultProjector;
        $first = $projector->project($this->source());
        $second = $projector->project($this->source(iq: 100, resultVersion: 2), $first);

        foreach ([
            [$this->source(), $second, 'ASSESSMENT_RESULT_VERSION_STALE'],
            [$this->source(resultVersion: 4), $second, 'ASSESSMENT_RESULT_VERSION_SEQUENCE_INVALID'],
            [$this->source(assessmentAttemptId: '01K4CJ5HQ9M6A7W8ZXN2T3V4B5', resultVersion: 3), $second, 'ASSESSMENT_RESULT_ATTEMPT_CONFLICT'],
        ] as [$source, $previous, $message]) {
            try {
                $projector->project($source, $previous);
                $this->fail('Expected result version binding to fail closed.');
            } catch (LogicException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_corrupt_previous_checksum_is_rejected(): void
    {
        $projector = new GenericAssessmentResultProjector;
        $previous = $projector->project($this->source());
        $previous['resultChecksum'] = str_repeat('0', 64);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ASSESSMENT_RESULT_PREVIOUS_INVALID');

        $projector->project($this->source(resultVersion: 2), $previous);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidSourceProvider')]
    public function test_missing_invalid_or_non_final_sources_fail_closed(array $overrides): void
    {
        $source = array_replace($this->source(), $overrides);
        if (array_key_exists('__unset', $source)) {
            unset($source[$source['__unset']], $source['__unset']);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ASSESSMENT_RESULT_SOURCE_INVALID');

        (new GenericAssessmentResultProjector)->project($source);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSourceProvider(): iterable
    {
        yield 'missing attempt' => [['__unset' => 'assessmentAttemptId']];
        yield 'attempt is not ULID' => [['assessmentAttemptId' => 'attempt-1']];
        yield 'attempt exceeds ULID range' => [['assessmentAttemptId' => 'Z1K4CJ5HQ9M6A7W8ZXN2T3V4B4']];
        yield 'boolean IQ' => [['iq' => true]];
        yield 'non finite IQ' => [['iq' => INF]];
        yield 'zero IQ' => [['iq' => 0]];
        yield 'negative IQ' => [['iq' => -1.25]];
        yield 'above psychometric ceiling' => [['iq' => 300.000001]];
        yield 'integer beyond exact binary float range' => [['iq' => 9_007_199_254_740_993]];
        yield 'blank engine version' => [['engineVersion' => '']];
        yield 'unsafe engine version' => [['engineVersion' => "engine\nsecret"]];
        yield 'invalid completed timestamp' => [['completedAt' => 'tomorrow']];
        yield 'impossible calendar timestamp' => [['completedAt' => '2026-02-31T10:15:30Z']];
        yield 'non final result' => [['finality' => 'PENDING']];
        yield 'zero version' => [['resultVersion' => 0]];
        yield 'revoked before completion' => [['revokedAt' => '2026-09-04T03:15:30Z']];
        yield 'unknown field' => [['privateNarrative' => 'must not leak']];
    }

    public function test_safe_audit_context_excludes_iq_and_raw_attempt_identifier(): void
    {
        $projector = new GenericAssessmentResultProjector;
        $payload = $projector->project($this->source());
        $audit = $projector->safeAuditContext($payload);

        $this->assertSame('generic_assessment_result.projected', $audit['event']);
        $this->assertSame(1, $audit['resultVersion']);
        $this->assertSame($payload['resultChecksum'], $audit['resultChecksum']);
        $this->assertSame(hash('sha256', $payload['assessmentAttemptId']), $audit['assessmentAttemptReference']);
        $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($payload['assessmentAttemptId'], $encoded);
        $this->assertStringNotContainsString('"iq"', $encoded);
        $this->assertStringNotContainsString('engineVersion', $encoded);
    }

    /** @return array<string, mixed> */
    private function source(
        int|float $iq = 99,
        int $resultVersion = 1,
        ?string $revokedAt = null,
        string $assessmentAttemptId = '01K4CJ5HQ9M6A7W8ZXN2T3V4B4',
    ): array {
        return [
            'assessmentAttemptId' => $assessmentAttemptId,
            'iq' => $iq,
            'engineVersion' => 'ist-2026.09.1',
            'completedAt' => '2026-09-05T10:15:30.123456+07:00',
            'finality' => 'FINALIZED',
            'revokedAt' => $revokedAt,
            'resultVersion' => $resultVersion,
        ];
    }
}
