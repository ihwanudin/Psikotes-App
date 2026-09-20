<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedIstResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * F2 lane G7 (2026-09-20), Lead-required guard: IST's `raw_score` never
 * needs to be negative (it is a correct-answer count), so
 * `generic_instrument_result_sources_contract_check`'s `raw_score >= 0`
 * clause was dropped at the database level only because Kraepelin's Hanker
 * needs it — see
 * database/migrations/2026_09_20_000200_widen_generic_instrument_result_source_fractional_columns.php.
 * That means IST must keep enforcing non-negative raw scores itself, in
 * this domain class, or the database-level guard's removal silently
 * weakens IST too.
 */
final class SealedIstResultTest extends TestCase
{
    public function test_a_negative_raw_score_is_rejected(): void
    {
        $subtests = $this->validSubtests();
        $subtests[0]['rawScore'] = -1;

        try {
            SealedIstResult::seal($this->source(), $this->scoringSource(), $subtests, $this->iq($subtests));
            $this->fail('A negative IST raw score must be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_IST_RESULT_INVALID', $exception->getMessage());
        }
    }

    public function test_a_valid_zero_raw_score_is_accepted(): void
    {
        $subtests = $this->validSubtests();
        $subtests[0]['rawScore'] = 0;

        $result = SealedIstResult::seal($this->source(), $this->scoringSource(), $subtests, $this->iq($subtests));

        $this->assertInstanceOf(SealedIstResult::class, $result);
    }

    /** @return array{id:int,code:string,version:string,sourceFile:string,checksum:string} */
    private function scoringSource(): array
    {
        return [
            'id' => 1, 'code' => 'ist', 'version' => 'synthetic-v1',
            'sourceFile' => 'synthetic-ist.json', 'checksum' => str_repeat('a', 64),
        ];
    }

    /** @return list<array{code:string,rawScore:int,standardScore:int,sourceScore:int,level:int,category:string,band:array{lo:int,hi:int}}> */
    private function validSubtests(): array
    {
        $subtests = [];
        foreach (['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'] as $code) {
            $subtests[] = [
                'code' => $code, 'rawScore' => 10, 'standardScore' => 100,
                'sourceScore' => 100, 'level' => 3, 'category' => 'synthetic',
                'band' => ['lo' => 90, 'hi' => 109],
            ];
        }

        return $subtests;
    }

    /**
     * @param  list<array{rawScore:int}>  $subtests
     * @return array{rawTotal:int,iq:int,level:int,sourceScores:list<int>,category:string,band:array{lo:int,hi:int}}
     */
    private function iq(array $subtests): array
    {
        return [
            'rawTotal' => array_sum(array_column($subtests, 'rawScore')),
            'iq' => 100, 'level' => 3, 'sourceScores' => [100],
            'category' => 'synthetic', 'band' => ['lo' => 90, 'hi' => 109],
        ];
    }

    private function source(): SealedGenericAnswerSet
    {
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-unit-test-only', 'total_duration_seconds' => 9,
            'subtests' => array_map(
                static fn (string $code): array => ['code' => $code, 'duration_seconds' => 1, 'item_count' => 1],
                ['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'],
            ),
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $answers = [];
        foreach (range(1, 9) as $item) {
            $answers[] = ['item_no' => $item, 'value' => 'A', 'revision' => 1, 'answered_at' => '2026-09-20T03:10:00.000000Z'];
        }

        return SealedGenericAnswerSet::seal(
            assessmentCaseId: 11, sessionId: 22, participantId: 33,
            sessionPublicId: '01K50SYNTHETICISTUNITTEST00', instrument: GenericAssessmentInstrument::Ist,
            attemptNo: 1, submittedAt: '2026-09-20T03:20:00.000000Z', answersRevision: 1,
            definition: $definition, answers: $answers,
        );
    }
}
