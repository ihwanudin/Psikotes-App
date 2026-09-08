<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Domain\Review\ProfessionalOverridePolicy;
use App\Domain\Review\ReportSigningSnapshotComposer;
use App\Domain\Review\ReportSigningTransitionPolicy;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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
        yield 'unresolved G7' => ['g7', 'G7_ASPECTS_UNRESOLVED'];
        yield 'target field' => ['field', 'TARGET_FIELD_REQUIRED'];
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
        $input['unresolved_g7_aspects'] = ['D5'];
        $input['target_field'] = '';
        $input['narrative_clusters'] = ['A' => null, 'B' => '', 'C' => ' ', 'D' => "\n"];

        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $this->snapshot($input));

        $this->assertSame([
            'V2_PROCEDURE_NOTE_REQUIRED',
            'ACCOMPANIMENT_CONDITIONS_REQUIRED',
            'G7_ASPECTS_UNRESOLVED',
            'TARGET_FIELD_REQUIRED',
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
            'g7' => $input['unresolved_g7_aspects'] = ['C4'],
            'field' => $input['target_field'] = null,
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
        $discrepancies = [];
        foreach (['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'] as $aspect) {
            $requiresReview = in_array($aspect, $input['unresolved_g7_aspects'], true);
            $sources = $requiresReview
                ? [['source' => 'SOURCE_A', 'level' => 1], ['source' => 'SOURCE_B', 'level' => 3]]
                : [['source' => 'SOURCE_A', 'level' => 3]];
            $discrepancies[] = [
                'result' => (new AspectSourceDiscrepancyPolicy)->evaluate(['aspect' => $aspect, 'sources' => $sources]),
                'review_resolved' => false,
            ];
        }

        $recommendation = $input['validity'] === 'V3'
            ? [
                'type' => 'publication_blocked',
                'publication_blocked' => true,
                'reason_code' => 'VALIDITY_V3',
                'provenance' => ['standard_version' => 'GA-2026.08', 'field_code' => 'KAIGO', 'iq' => 100, 'validity' => 'V3'],
            ]
            : [
                'type' => 'recommendation_label',
                'publication_blocked' => false,
                'initial_label' => 'DISARANKAN',
                'label' => 'DISARANKAN',
                'review_required' => false,
                'review_reason_codes' => [],
                'provenance' => [
                    'standard_version' => 'GA-2026.08',
                    'field_code' => 'KAIGO',
                    'iq' => 100,
                    'validity' => $input['validity'],
                    'critical_belum_aspects' => [],
                    'belum_aspects' => [],
                    'grey_aspects' => [],
                    'zone_counts' => ['OK' => 18, 'GREY' => 0, 'BELUM' => 0, 'UNASSESSED' => 0],
                ],
            ];

        $overrides = [];
        if ($input['validity'] !== 'V3' && $input['label'] !== 'DISARANKAN') {
            $overrides[] = [
                'result' => (new ProfessionalOverridePolicy)->labelOverride([
                    'system_label' => 'DISARANKAN',
                    'final_label' => $input['label'],
                    'reason' => 'Pertimbangan profesional telah dicatat secara lengkap.',
                ]),
                'audit_recorded' => true,
                'recalculation_completed' => false,
            ];
        }

        return ReportSigningSnapshotComposer::compose([
            'recommendation' => $recommendation,
            'discrepancies' => $discrepancies,
            'overrides' => $overrides,
            'procedure_note' => $input['procedure_note'],
            'accompaniment_conditions' => $input['accompaniment_conditions'],
            'target_field' => $input['target_field'],
            'narrative_clusters' => $input['narrative_clusters'],
        ]);
    }
}
