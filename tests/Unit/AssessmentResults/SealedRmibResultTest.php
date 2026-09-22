<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentResults;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedRmibResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * F2 lane G7 (2026-09-20), Lead-required guard — see SealedIstResultTest's
 * docblock for why this exists: RMIB's `raw_score` (a rank-total sum) never
 * needs to be negative, so it must keep rejecting negative/low values itself
 * now that the database-level `raw_score >= 0` check was dropped (for
 * Kraepelin's Hanker only). RMIB enforces a floor of 8 (ADR-0032 PR3,
 * 2026-09-23: was 9 pre-tiering, when all 9 groups always contributed to
 * every category; P3's single-group-exclusion tier can drop a category to
 * 8 contributing cells), which is still strictly stronger than >= 0.
 */
final class SealedRmibResultTest extends TestCase
{
    private const CATEGORY_CODES = ['Out', 'Me', 'Comp', 'Sci', 'Prs', 'Aesth', 'Lit', 'Mus', 'S.Se', 'Cler', 'Prac', 'Med'];

    public function test_a_negative_raw_score_is_rejected(): void
    {
        $categories = $this->validCategories();
        $categories[0]['rawScore'] = -1;

        try {
            SealedRmibResult::seal($this->source(), $this->scoringSource(), $categories, reviewRequired: false, excludedGroups: []);
            $this->fail('A negative RMIB raw score must be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_RMIB_RESULT_INVALID', $exception->getMessage());
        }
    }

    public function test_a_below_floor_raw_score_of_seven_is_rejected(): void
    {
        $categories = $this->validCategories();
        $categories[0]['rawScore'] = 7;

        try {
            SealedRmibResult::seal($this->source(), $this->scoringSource(), $categories, reviewRequired: false, excludedGroups: []);
            $this->fail('An RMIB raw score below the eight-cell floor must be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_RMIB_RESULT_INVALID', $exception->getMessage());
        }
    }

    public function test_a_valid_minimum_raw_score_of_eight_is_accepted(): void
    {
        $categories = $this->validCategories();
        $categories[0]['rawScore'] = 8;

        $result = SealedRmibResult::seal($this->source(), $this->scoringSource(), $categories, reviewRequired: false, excludedGroups: []);

        $this->assertInstanceOf(SealedRmibResult::class, $result);
    }

    public function test_review_required_must_agree_with_excluded_groups_presence(): void
    {
        $categories = $this->validCategories();

        try {
            SealedRmibResult::seal($this->source(), $this->scoringSource(), $categories, reviewRequired: false, excludedGroups: [3]);
            $this->fail('reviewRequired=false with a non-empty excludedGroups must be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_RMIB_RESULT_INVALID', $exception->getMessage());
        }

        try {
            SealedRmibResult::seal($this->source(), $this->scoringSource(), $categories, reviewRequired: true, excludedGroups: []);
            $this->fail('reviewRequired=true with an empty excludedGroups must be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_RMIB_RESULT_INVALID', $exception->getMessage());
        }
    }

    public function test_two_or_more_excluded_groups_are_rejected(): void
    {
        $categories = $this->validCategories();

        try {
            SealedRmibResult::seal($this->source(), $this->scoringSource(), $categories, reviewRequired: true, excludedGroups: [3, 5]);
            $this->fail('2+ excluded groups is the not-scorable case and must never reach SealedRmibResult::seal().');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_RMIB_RESULT_INVALID', $exception->getMessage());
        }
    }

    /** @return array{id:int,code:string,version:string,sourceFile:string,checksum:string} */
    private function scoringSource(): array
    {
        return [
            'id' => 1, 'code' => 'rmib', 'version' => 'synthetic-v1',
            'sourceFile' => 'synthetic-rmib.json', 'checksum' => str_repeat('a', 64),
        ];
    }

    /** @return list<array{code:string,rawScore:int,standardScore:int,sourceScore:int,level:int,category:string,band:array{lo:int,hi:int}}> */
    private function validCategories(): array
    {
        $categories = [];
        foreach (self::CATEGORY_CODES as $offset => $code) {
            $rank = ($offset % 12) + 1;
            $categories[] = [
                'code' => $code, 'rawScore' => 50, 'standardScore' => $rank,
                'sourceScore' => 5, 'level' => 3, 'category' => 'synthetic-'.$code,
                'band' => ['lo' => $rank, 'hi' => $rank],
            ];
        }

        return $categories;
    }

    private function source(): SealedGenericAnswerSet
    {
        $subtests = [];
        for ($group = 1; $group <= 9; $group++) {
            $subtests[] = ['code' => 'G'.$group, 'duration_seconds' => 1, 'item_count' => 12];
        }
        $definitionSource = [
            'instrument' => 'rmib', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-unit-test-only', 'total_duration_seconds' => 9,
            'subtests' => $subtests, 'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $answers = [];
        foreach (range(1, 108) as $item) {
            $answers[] = ['item_no' => $item, 'value' => '1', 'revision' => 1, 'answered_at' => '2026-09-20T03:10:00.000000Z'];
        }

        return SealedGenericAnswerSet::seal(
            assessmentCaseId: 11, sessionId: 22, participantId: 33,
            sessionPublicId: '01K50SYNTHETICRMIBUNITTEST0', instrument: GenericAssessmentInstrument::Rmib,
            attemptNo: 1, submittedAt: '2026-09-20T03:20:00.000000Z', answersRevision: 1,
            definition: $definition, answers: $answers,
        );
    }
}
