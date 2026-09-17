<?php

declare(strict_types=1);

namespace Tests\Integration\Review;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Domain\Eligibility\EligibilityZoneCalculator;
use App\Domain\Eligibility\RecommendationLabelPolicy;
use App\Domain\Review\G7AspectResolution;
use App\Domain\Review\G7ReviewSet;
use App\Domain\Review\ProfessionalOverridePolicy;
use App\Domain\Review\ReportReviewStateMachine;
use App\Domain\Review\ReportSigningPrerequisitePolicy;
use App\Domain\Review\ReportSigningSnapshotComposer;
use App\Domain\Review\ReportSigningTransitionPolicy;
use App\Domain\Review\ReviewedEligibilityDecision;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use TypeError;

final class ReportSigningGuardrailsAcceptanceTest extends TestCase
{
    public function test_complete_under_review_report_signs_before_it_can_publish(): void
    {
        $prerequisites = new ReportSigningPrerequisitePolicy;
        $stateMachine = new ReportReviewStateMachine;
        $transitionPolicy = new ReportSigningTransitionPolicy($prerequisites, $stateMachine);

        $prerequisiteResult = $prerequisites->evaluate($this->completeInput());
        $signing = $transitionPolicy->attempt('UNDER_REVIEW', $this->snapshot($this->completeInput()));

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

        (new ReportSigningTransitionPolicy)->attempt($state, $this->snapshot($this->completeInput()));
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
        $input['narrative_clusters']['A'] = null;

        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $this->snapshot($input));

        self::assertFalse($result['can_sign']);
        self::assertSame(['NARRATIVE_CLUSTER_A_REQUIRED'], $result['blocking_reason_codes']);
        self::assertNull($result['transition']);
        self::assertFalse($result['prerequisite_provenance']['narrative_clusters_present']['A']);
    }

    public function test_v3_recommendation_without_a_label_cannot_transition_to_signed(): void
    {
        $reporting = $this->canonicalReporting();
        $zone = (new EligibilityZoneCalculator(
            $reporting['standard_version'],
            $reporting['base_standards'],
            $reporting['fields'],
        ))->calculate(array_fill_keys($this->aspectCodes(), 3), 'KAIGO');
        $recommendation = (new RecommendationLabelPolicy)->decide($zone, 100, 'V3');
        $input = $this->completeInput();
        $input['validity'] = $recommendation['provenance']['validity'];
        $input['label'] = $recommendation['label'] ?? null;

        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $this->snapshot($input));

        self::assertArrayNotHasKey('label', $recommendation);
        self::assertFalse($result['can_sign']);
        self::assertSame(['VALIDITY_V3'], $result['blocking_reason_codes']);
        self::assertNull($result['prerequisite_provenance']['label']);
        self::assertNull($result['transition']);
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
        $signing = $transitionPolicy->attempt('UNDER_REVIEW', $this->snapshot($input));

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
        $signing = $transitionPolicy->attempt('UNDER_REVIEW', $this->snapshot($input));

        self::assertTrue($prerequisiteResult['can_sign']);
        self::assertTrue($prerequisiteResult['provenance']['accompaniment_conditions_present']);
        self::assertTrue($signing['can_sign']);
        self::assertSame([], $signing['blocking_reason_codes']);
        self::assertNotNull($signing['transition']);
        self::assertSame('SIGNED', $signing['transition']['to_state']);
    }

    public function test_raw_prerequisite_array_is_rejected_at_the_signing_boundary(): void
    {
        $this->expectException(TypeError::class);

        $policy = new ReportSigningTransitionPolicy;
        (new ReflectionMethod($policy, 'attempt'))->invoke($policy, 'UNDER_REVIEW', $this->completeInput());
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

    /** @return list<string> */
    private function aspectCodes(): array
    {
        return ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];
    }

    /**
     * @param  array<mixed>  $input
     */
    private function snapshot(array $input): ReportSigningSnapshotComposer
    {
        $reporting = $this->canonicalReporting();
        $baseline = EligibilityDecisionSnapshot::create([
            'levels' => array_fill_keys($this->aspectCodes(), 5),
            'field_code' => 'KAIGO',
            'iq' => 100,
            'validity' => $input['validity'],
            'standard_configuration' => $reporting,
            'eligibility_source_versions' => [
                'ist' => 'F0-2026.08', 'papi' => 'F0-2026.08', 'kraepelin' => 'F0-2026.08',
                'rmib' => 'F0-2026.08', 'reporting' => $reporting['standard_version'],
            ],
        ]);
        $labelOverride = null;
        if ($input['validity'] !== 'V3' && $input['label'] !== 'DISARANKAN') {
            $labelOverride = (new ProfessionalOverridePolicy)->labelOverride([
                'system_label' => 'DISARANKAN',
                'final_label' => $input['label'],
                'reason' => 'Pertimbangan profesional telah dicatat secara lengkap.',
            ]);
        }
        $reviewed = ReviewedEligibilityDecision::create($baseline, [], $labelOverride);
        $resolutions = array_map(static fn (string $aspect): G7AspectResolution => G7AspectResolution::notRequired(
            (new AspectSourceDiscrepancyPolicy)->evaluate([
                'aspect' => $aspect,
                'sources' => [['source' => 'CANONICAL_SOURCE', 'level' => 5]],
            ]),
            5,
        ), $this->aspectCodes());

        return ReportSigningSnapshotComposer::compose($reviewed, G7ReviewSet::fromResolutions($resolutions), [
            'procedure_note' => $input['procedure_note'],
            'accompaniment_conditions' => $input['accompaniment_conditions'],
            'narrative_clusters' => $input['narrative_clusters'],
        ]);
    }

    /** @return array{standard_version: string, base_standards: array<mixed>, fields: array<mixed>} */
    private function canonicalReporting(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/reporting.json');
        if (! is_string($contents)) {
            throw new RuntimeException('Canonical reporting data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)
            || ! is_string($data['standard_version'] ?? null)
            || ! is_array($data['base_standards'] ?? null)
            || ! is_array($data['fields'] ?? null)) {
            throw new RuntimeException('Canonical reporting data is invalid.');
        }

        return [
            'standard_version' => $data['standard_version'],
            'base_standards' => $data['base_standards'],
            'fields' => $data['fields'],
        ];
    }
}
