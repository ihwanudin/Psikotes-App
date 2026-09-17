<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Domain\Review\G7AspectResolution;
use App\Domain\Review\G7ReviewSet;
use App\Domain\Review\ProfessionalOverridePolicy;
use App\Domain\Review\ReportSigningSnapshotComposer;
use App\Domain\Review\ReportSigningTransitionPolicy;
use App\Domain\Review\ReviewedEligibilityDecision;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use TypeError;

final class ReportSigningTransitionPolicyTest extends TestCase
{
    public function test_valid_v1_prerequisites_transition_under_review_to_signed(): void
    {
        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $this->snapshot($this->validInput()));

        $this->assertSame([
            'can_sign' => true,
            'current_state' => 'UNDER_REVIEW',
            'target_state' => 'SIGNED',
            'blocking_reason_codes' => [],
            'prerequisite_provenance' => $this->validProvenance(),
            'transition' => [
                'from_state' => 'UNDER_REVIEW',
                'to_state' => 'SIGNED',
                'transitioned' => true,
                'terminal' => false,
                'provenance' => [
                    'transition_kind' => 'STANDARD',
                    'invalidity_declared' => false,
                ],
            ],
        ], $result);
    }

    public function test_valid_v2_considered_prerequisites_can_transition_to_signed(): void
    {
        $input = $this->validInput();
        $input['validity'] = 'V2';
        $input['procedure_note'] = 'Gangguan koneksi telah ditinjau secara profesional.';
        $input['label'] = 'DIPERTIMBANGKAN';
        $input['accompaniment_conditions'] = 'Pendampingan diberikan pada masa adaptasi kerja.';

        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $this->snapshot($input));

        $this->assertTrue($result['can_sign']);
        $this->assertSame([], $result['blocking_reason_codes']);
        $this->assertSame('V2', $result['prerequisite_provenance']['validity']);
        $this->assertTrue($result['prerequisite_provenance']['procedure_note_present']);
        $this->assertTrue($result['prerequisite_provenance']['accompaniment_conditions_present']);
        $this->assertSame('SIGNED', $result['transition']['to_state']);
    }

    #[DataProvider('individualBlockers')]
    public function test_each_prerequisite_blocker_returns_no_transition(string $mutation, string $expectedCode): void
    {
        $input = $this->validInput();
        $this->applyMutation($input, $mutation);

        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $this->snapshot($input));

        $this->assertFalse($result['can_sign']);
        $this->assertSame([$expectedCode], $result['blocking_reason_codes']);
        $this->assertNull($result['transition']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function individualBlockers(): iterable
    {
        yield 'V3' => ['v3', 'VALIDITY_V3'];
        yield 'V2 note' => ['v2_note', 'V2_PROCEDURE_NOTE_REQUIRED'];
        yield 'accompaniment conditions' => ['conditions', 'ACCOMPANIMENT_CONDITIONS_REQUIRED'];
        yield 'narrative A' => ['A', 'NARRATIVE_CLUSTER_A_REQUIRED'];
        yield 'narrative B' => ['B', 'NARRATIVE_CLUSTER_B_REQUIRED'];
        yield 'narrative C' => ['C', 'NARRATIVE_CLUSTER_C_REQUIRED'];
        yield 'narrative D' => ['D', 'NARRATIVE_CLUSTER_D_REQUIRED'];
    }

    public function test_combined_prerequisites_preserve_all_stable_blocking_codes(): void
    {
        $input = $this->validInput();
        $input['validity'] = 'V2';
        $input['procedure_note'] = ' ';
        $input['label'] = 'DIPERTIMBANGKAN';
        $input['accompaniment_conditions'] = null;
        $input['narrative_clusters'] = ['A' => null, 'B' => '', 'C' => ' ', 'D' => "\n"];

        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $this->snapshot($input));

        $this->assertSame([
            'V2_PROCEDURE_NOTE_REQUIRED',
            'ACCOMPANIMENT_CONDITIONS_REQUIRED',
            'NARRATIVE_CLUSTER_A_REQUIRED',
            'NARRATIVE_CLUSTER_B_REQUIRED',
            'NARRATIVE_CLUSTER_C_REQUIRED',
            'NARRATIVE_CLUSTER_D_REQUIRED',
        ], $result['blocking_reason_codes']);
        $this->assertNull($result['transition']);
    }

    #[DataProvider('nonReviewStates')]
    public function test_every_state_other_than_under_review_fails_closed(string $state): void
    {
        $this->expectException(DomainException::class);

        (new ReportSigningTransitionPolicy)->attempt($state, $this->snapshot($this->validInput()));
    }

    /** @return iterable<string, array{string}> */
    public static function nonReviewStates(): iterable
    {
        foreach (['DRAFT_SCORED', 'DRAFT_NARRATED', 'REVISED', 'SIGNED', 'PUBLISHED', 'REVOKED', 'VOID'] as $state) {
            yield $state => [$state];
        }
    }

    public function test_repeated_evaluation_is_deterministic(): void
    {
        $policy = new ReportSigningTransitionPolicy;
        $input = $this->snapshot($this->validInput());

        $this->assertSame(
            $policy->attempt('UNDER_REVIEW', $input),
            $policy->attempt('UNDER_REVIEW', $input),
        );
    }

    public function test_noncanonical_current_state_is_rejected_by_the_state_machine(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReportSigningTransitionPolicy)->attempt('under_review', $this->snapshot($this->validInput()));
    }

    public function test_raw_prerequisite_payload_is_rejected_at_the_transition_boundary(): void
    {
        $this->expectException(TypeError::class);

        $policy = new ReportSigningTransitionPolicy;
        (new ReflectionMethod($policy, 'attempt'))->invoke($policy, 'UNDER_REVIEW', $this->validInput());
    }

    /** @param array<mixed> $input */
    private function applyMutation(array &$input, string $mutation): void
    {
        match ($mutation) {
            'v3' => [$input['validity'], $input['label']] = ['V3', null],
            'v2_note' => [$input['validity'], $input['procedure_note']] = ['V2', null],
            'conditions' => [$input['label'], $input['accompaniment_conditions']] = ['DIPERTIMBANGKAN', null],
            default => $input['narrative_clusters'][$mutation] = ' ',
        };
    }

    /** @return array<mixed> */
    private function validInput(): array
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

    /** @return array<mixed> */
    private function validProvenance(): array
    {
        return [
            'validity' => 'V1',
            'label' => 'DISARANKAN',
            'procedure_note_present' => false,
            'accompaniment_conditions_present' => false,
            'unresolved_g7_aspects' => [],
            'overrides' => [],
            'target_field' => 'KAIGO',
            'narrative_clusters_present' => ['A' => true, 'B' => true, 'C' => true, 'D' => true],
        ];
    }

    /** @param array<mixed> $input */
    private function snapshot(array $input): ReportSigningSnapshotComposer
    {
        $aspects = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];
        $reporting = $this->canonicalReporting();
        $baseline = EligibilityDecisionSnapshot::create([
            'levels' => array_fill_keys($aspects, 5),
            'field_code' => 'KAIGO',
            'iq' => 100,
            'validity' => $input['validity'],
            'standard_configuration' => $reporting,
            'eligibility_source_versions' => [
                'ist' => 'F0-2026.08', 'papi' => 'F0-2026.08', 'kraepelin' => 'F0-2026.08',
                'rmib' => 'F0-2026.08', 'reporting' => $reporting['standard_version'],
            ],
        ]);
        $overrides = [];
        if ($input['validity'] !== 'V3' && $input['label'] !== 'DISARANKAN') {
            $labelOverride = (new ProfessionalOverridePolicy)->labelOverride([
                'system_label' => 'DISARANKAN', 'final_label' => $input['label'],
                'reason' => 'Pertimbangan profesional telah dicatat secara lengkap.',
            ]);
        } else {
            $labelOverride = null;
        }
        $reviewed = ReviewedEligibilityDecision::create($baseline, $overrides, $labelOverride);
        $resolutions = array_map(static fn (string $aspect): G7AspectResolution => G7AspectResolution::notRequired(
            (new AspectSourceDiscrepancyPolicy)->evaluate([
                'aspect' => $aspect, 'sources' => [['source' => 'SOURCE_A', 'level' => 5]],
            ]),
            5,
        ), $aspects);

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
        if (! is_array($data) || ! is_string($data['standard_version'] ?? null)
            || ! is_array($data['base_standards'] ?? null) || ! is_array($data['fields'] ?? null)) {
            throw new RuntimeException('Canonical reporting data is incomplete.');
        }

        return ['standard_version' => $data['standard_version'], 'base_standards' => $data['base_standards'], 'fields' => $data['fields']];
    }
}
