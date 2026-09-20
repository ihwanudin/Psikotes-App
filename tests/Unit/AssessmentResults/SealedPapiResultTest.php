<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedPapiResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * F2 lane G7 (2026-09-20), Lead-required guard — see SealedIstResultTest's
 * docblock for why this exists: PAPI's `raw_score` (0-9 forced-choice count)
 * never needs to be negative, so it must keep rejecting negative values
 * itself now that the database-level `raw_score >= 0` check was dropped
 * (for Kraepelin's Hanker only).
 */
final class SealedPapiResultTest extends TestCase
{
    private const DIMENSION_CODES = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'I', 'K', 'L', 'N', 'O', 'P', 'R', 'S', 'T', 'V', 'W', 'X', 'Z',
    ];

    public function test_a_negative_raw_score_is_rejected(): void
    {
        $dimensions = $this->validDimensions();
        $dimensions[0]['rawScore'] = -1;
        $dimensions[0]['sourceScore'] = -1;

        try {
            SealedPapiResult::seal($this->source(), $this->scoringSource(), $dimensions);
            $this->fail('A negative PAPI raw score must be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_PAPI_RESULT_INVALID', $exception->getMessage());
        }
    }

    public function test_a_valid_zero_raw_score_is_accepted(): void
    {
        $dimensions = $this->validDimensions();
        $dimensions[0]['rawScore'] = 0;
        $dimensions[0]['sourceScore'] = 0;

        $result = SealedPapiResult::seal($this->source(), $this->scoringSource(), $dimensions);

        $this->assertInstanceOf(SealedPapiResult::class, $result);
    }

    /** @return array{id:int,code:string,version:string,sourceFile:string,checksum:string} */
    private function scoringSource(): array
    {
        return [
            'id' => 1, 'code' => 'papi', 'version' => 'synthetic-v1',
            'sourceFile' => 'synthetic-papi.json', 'checksum' => str_repeat('a', 64),
        ];
    }

    /** @return list<array{code:string,rawScore:int,standardScore:int,sourceScore:int,level:int,category:string,band:array{lo:int,hi:int}}> */
    private function validDimensions(): array
    {
        $dimensions = [];
        foreach (self::DIMENSION_CODES as $offset => $code) {
            $dimensions[] = [
                'code' => $code, 'rawScore' => 3, 'standardScore' => 1,
                'sourceScore' => 3, 'level' => 3, 'category' => $offset % 2 === 0 ? 'ROLE' : 'NEED',
                'band' => ['lo' => 3, 'hi' => 6],
            ];
        }

        return $dimensions;
    }

    private function source(): SealedGenericAnswerSet
    {
        $definitionSource = [
            'instrument' => 'papi', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-unit-test-only', 'total_duration_seconds' => 90,
            'subtests' => [['code' => 'PAPI', 'duration_seconds' => 90, 'item_count' => 90]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $answers = [];
        foreach (range(1, 90) as $item) {
            $answers[] = ['item_no' => $item, 'value' => 'a', 'revision' => 1, 'answered_at' => '2026-09-20T03:10:00.000000Z'];
        }

        return SealedGenericAnswerSet::seal(
            assessmentCaseId: 11, sessionId: 22, participantId: 33,
            sessionPublicId: '01K50SYNTHETICPAPIUNITTEST0', instrument: GenericAssessmentInstrument::Papi,
            attemptNo: 1, submittedAt: '2026-09-20T03:20:00.000000Z', answersRevision: 1,
            definition: $definition, answers: $answers,
        );
    }
}
