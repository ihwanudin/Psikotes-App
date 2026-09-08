<?php

declare(strict_types=1);

namespace Tests\Integration\Review;

use App\Domain\Review\ReportReviewStateMachine;
use App\Domain\Review\ReportSigningPrerequisitePolicy;
use App\Domain\Review\ReportSigningTransitionPolicy;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportSigningGuardrailsAcceptanceTest extends TestCase
{
    public function test_complete_under_review_report_signs_before_it_can_publish(): void
    {
        $prerequisites = new ReportSigningPrerequisitePolicy;
        $stateMachine = new ReportReviewStateMachine;
        $transitionPolicy = new ReportSigningTransitionPolicy($prerequisites, $stateMachine);

        $prerequisiteResult = $prerequisites->evaluate($this->completeInput());
        $signing = $transitionPolicy->attempt('UNDER_REVIEW', $this->completeInput());

        self::assertTrue($prerequisiteResult['can_sign']);
        self::assertSame([], $prerequisiteResult['blocking_reason_codes']);
        self::assertTrue($signing['can_sign']);
        self::assertSame([], $signing['blocking_reason_codes']);
        self::assertNotNull($signing['transition']);
        self::assertSame('UNDER_REVIEW', $signing['transition']['from_state']);
        self::assertSame('SIGNED', $signing['transition']['to_state']);

        $publication = $stateMachine->transition($signing['transition']['to_state'], 'PUBLISHED');

        self::assertSame([
            'from_state' => 'SIGNED',
            'to_state' => 'PUBLISHED',
            'transitioned' => true,
            'terminal' => false,
            'provenance' => [
                'transition_kind' => 'STANDARD',
                'invalidity_declared' => false,
            ],
        ], $publication);
    }

    #[DataProvider('statesThatCannotSignDirectly')]
    public function test_t15_g5_draft_or_revised_state_cannot_sign_directly(string $state): void
    {
        $this->expectException(DomainException::class);

        (new ReportSigningTransitionPolicy)->attempt($state, $this->completeInput());
    }

    /** @return iterable<string, array{string}> */
    public static function statesThatCannotSignDirectly(): iterable
    {
        yield 'scored draft' => ['DRAFT_SCORED'];
        yield 'narrated draft' => ['DRAFT_NARRATED'];
        yield 'revised report' => ['REVISED'];
    }

    #[DataProvider('statesThatCannotPublishDirectly')]
    public function test_t15_g5_only_a_signed_report_can_publish(string $state): void
    {
        $this->expectException(DomainException::class);

        (new ReportReviewStateMachine)->transition($state, 'PUBLISHED');
    }

    /** @return iterable<string, array{string}> */
    public static function statesThatCannotPublishDirectly(): iterable
    {
        yield 'scored draft' => ['DRAFT_SCORED'];
        yield 'narrated draft' => ['DRAFT_NARRATED'];
        yield 'review in progress' => ['UNDER_REVIEW'];
        yield 'revised report' => ['REVISED'];
    }

    public function test_incomplete_under_review_report_returns_no_signing_transition(): void
    {
        $input = $this->completeInput();
        $input['target_field'] = null;

        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $input);

        self::assertFalse($result['can_sign']);
        self::assertSame(['TARGET_FIELD_REQUIRED'], $result['blocking_reason_codes']);
        self::assertNull($result['transition']);
        self::assertNull($result['prerequisite_provenance']['target_field']);
    }

    #[DataProvider('missingConditions')]
    public function test_t22_g9_considered_report_without_meaningful_conditions_cannot_sign(?string $conditions): void
    {
        $input = $this->completeInput();
        $input['label'] = 'DIPERTIMBANGKAN';
        $input['accompaniment_conditions'] = $conditions;
        $prerequisites = new ReportSigningPrerequisitePolicy;
        $transitionPolicy = new ReportSigningTransitionPolicy($prerequisites, new ReportReviewStateMachine);

        $prerequisiteResult = $prerequisites->evaluate($input);
        $signing = $transitionPolicy->attempt('UNDER_REVIEW', $input);

        self::assertFalse($prerequisiteResult['can_sign']);
        self::assertSame(['ACCOMPANIMENT_CONDITIONS_REQUIRED'], $prerequisiteResult['blocking_reason_codes']);
        self::assertFalse($prerequisiteResult['provenance']['accompaniment_conditions_present']);
        self::assertFalse($signing['can_sign']);
        self::assertSame(['ACCOMPANIMENT_CONDITIONS_REQUIRED'], $signing['blocking_reason_codes']);
        self::assertNull($signing['transition']);
    }

    /** @return iterable<string, array{string|null}> */
    public static function missingConditions(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => [" \t\n "];
    }

    public function test_t22_g9_meaningful_conditions_allow_considered_report_to_sign(): void
    {
        $input = $this->completeInput();
        $input['label'] = 'DIPERTIMBANGKAN';
        $input['accompaniment_conditions'] = 'Pendampingan kerja diberikan selama masa adaptasi awal.';
        $prerequisites = new ReportSigningPrerequisitePolicy;
        $transitionPolicy = new ReportSigningTransitionPolicy($prerequisites, new ReportReviewStateMachine);

        $prerequisiteResult = $prerequisites->evaluate($input);
        $signing = $transitionPolicy->attempt('UNDER_REVIEW', $input);

        self::assertTrue($prerequisiteResult['can_sign']);
        self::assertTrue($prerequisiteResult['provenance']['accompaniment_conditions_present']);
        self::assertTrue($signing['can_sign']);
        self::assertSame([], $signing['blocking_reason_codes']);
        self::assertNotNull($signing['transition']);
        self::assertSame('SIGNED', $signing['transition']['to_state']);
    }

    /** @return array<mixed> */
    private function completeInput(): array
    {
        return [
            'validity' => 'V1',
            'procedure_note' => null,
            'label' => 'DISARANKAN',
            'accompaniment_conditions' => null,
            'unresolved_g7_aspects' => [],
            'overrides' => [],
            'target_field' => 'KAIGO',
            'narrative_clusters' => [
                'A' => 'Kemampuan umum telah dirangkum.',
                'B' => 'Cara kerja telah dirangkum.',
                'C' => 'Kepribadian telah dirangkum.',
                'D' => 'Minat kerja telah dirangkum.',
            ],
        ];
    }
}
