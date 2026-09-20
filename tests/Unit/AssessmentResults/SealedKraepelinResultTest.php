<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentResults;

use App\Domain\AssessmentResults\SealedKraepelinResult;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * F2 lane G7 (2026-09-20), Lead-required guard: the ledger's global
 * `raw_score >= 0` check was dropped only because Kraepelin's Hanker is
 * routinely negative
 * (database/migrations/2026_09_20_000200_widen_generic_instrument_result_source_fractional_columns.php).
 * Panker/Tianker/Janker are never negative in any configured norm group
 * (database/seeders/data/kraepelin.json has no negative band boundary for
 * them), so this class must reject a negative value for those three itself
 * — allowing negative for all four factors "because one of them needs it"
 * would silently weaken the other three.
 */
final class SealedKraepelinResultTest extends TestCase
{
    public function test_a_negative_hanker_is_accepted(): void
    {
        $factors = $this->validFactors();
        $factors[2]['rawScore'] = -0.622; // HANKER is index 2.

        $result = SealedKraepelinResult::seal(
            $this->identity(),
            $this->scoringSource(),
            'S1/S2 (IPA)',
            $factors,
            str_repeat('a', 64),
        );

        $this->assertInstanceOf(SealedKraepelinResult::class, $result);
    }

    public function test_a_negative_panker_is_rejected(): void
    {
        $this->assertFactorIndexRejectsNegative(0, 'PANKER');
    }

    public function test_a_negative_tianker_is_rejected(): void
    {
        $this->assertFactorIndexRejectsNegative(1, 'TIANKER');
    }

    public function test_a_negative_janker_is_rejected(): void
    {
        $this->assertFactorIndexRejectsNegative(3, 'JANKER');
    }

    private function assertFactorIndexRejectsNegative(int $index, string $code): void
    {
        $factors = $this->validFactors();
        $this->assertSame($code, $factors[$index]['code']);
        $factors[$index]['rawScore'] = -1;

        try {
            SealedKraepelinResult::seal(
                $this->identity(),
                $this->scoringSource(),
                'S1/S2 (IPA)',
                $factors,
                str_repeat('a', 64),
            );
            $this->fail("A negative {$code} must be rejected.");
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_KRAEPELIN_RESULT_INVALID', $exception->getMessage());
        }
    }

    /** @return array{assessmentCaseId:int,sessionId:int,participantId:int,sessionPublicId:string,attemptNo:int,submittedAt:string,answersRevision:int,sessionDefinition:array<string,mixed>} */
    private function identity(): array
    {
        return [
            'assessmentCaseId' => 11, 'sessionId' => 22, 'participantId' => 33,
            'sessionPublicId' => '01K50SYNTHETICKRAEPELINUNIT', 'attemptNo' => 1,
            'submittedAt' => '2026-09-20T03:20:00.000000Z', 'answersRevision' => 1,
            'sessionDefinition' => ['instrument' => 'kraepelin', 'version' => 'synthetic-v1'],
        ];
    }

    /** @return array{id:int,code:string,version:string,sourceFile:string,checksum:string} */
    private function scoringSource(): array
    {
        return [
            'id' => 1, 'code' => 'kraepelin', 'version' => 'synthetic-v1',
            'sourceFile' => 'synthetic-kraepelin.json', 'checksum' => str_repeat('b', 64),
        ];
    }

    /** @return list<array{code:string,rawScore:int|float,standardScore:int,sourceScore:int,level:int,category:string,band:array{lo:int|float|null,hi:int|float|null}}> */
    private function validFactors(): array
    {
        return [
            ['code' => 'PANKER', 'rawScore' => 15.86, 'standardScore' => 7, 'sourceScore' => 7, 'level' => 4, 'category' => 'Baik', 'band' => ['lo' => 14.973, 'hi' => 16.09]],
            ['code' => 'TIANKER', 'rawScore' => 7, 'standardScore' => 6, 'sourceScore' => 6, 'level' => 3, 'category' => 'Sedang', 'band' => ['lo' => 3, 'hi' => 8]],
            ['code' => 'HANKER', 'rawScore' => -0.622, 'standardScore' => 4, 'sourceScore' => 4, 'level' => 2, 'category' => 'Kurang', 'band' => ['lo' => -1.209, 'hi' => -0.469]],
            ['code' => 'JANKER', 'rawScore' => 7, 'standardScore' => 6, 'sourceScore' => 6, 'level' => 3, 'category' => 'Sedang', 'band' => ['lo' => 7, 'hi' => 8]],
        ];
    }
}
