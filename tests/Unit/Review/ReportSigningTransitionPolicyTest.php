<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Domain\Review\ReportSigningTransitionPolicy;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportSigningTransitionPolicyTest extends TestCase
{
    public function test_valid_v1_prerequisites_transition_under_review_to_signed(): void
    {
        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $this->validInput());

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

        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $input);

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

        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $input);

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
        yield 'override reason' => ['override', 'OVERRIDE_REASON_MIN_LENGTH'];
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
        $input['overrides'] = [['type' => 'label', 'aspect' => null, 'reason' => 'singkat']];
        $input['target_field'] = '';
        $input['narrative_clusters'] = ['A' => null, 'B' => '', 'C' => ' ', 'D' => "\n"];

        $result = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $input);

        $this->assertSame([
            'V2_PROCEDURE_NOTE_REQUIRED',
            'ACCOMPANIMENT_CONDITIONS_REQUIRED',
            'G7_ASPECTS_UNRESOLVED',
            'OVERRIDE_REASON_MIN_LENGTH',
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

        (new ReportSigningTransitionPolicy)->attempt($state, $this->validInput());
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
        $input = $this->validInput();

        $this->assertSame(
            $policy->attempt('UNDER_REVIEW', $input),
            $policy->attempt('UNDER_REVIEW', $input),
        );
    }

    public function test_noncanonical_current_state_is_rejected_by_the_state_machine(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReportSigningTransitionPolicy)->attempt('under_review', $this->validInput());
    }

    public function test_invalid_prerequisite_payload_is_rejected_without_a_transition_result(): void
    {
        $input = [...$this->validInput(), 'extra' => true];
        $this->expectException(InvalidArgumentException::class);

        (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $input);
    }

    /** @param array<mixed> $input */
    private function applyMutation(array &$input, string $mutation): void
    {
        match ($mutation) {
            'v3' => $input['validity'] = 'V3',
            'v2_note' => [$input['validity'], $input['procedure_note']] = ['V2', null],
            'conditions' => [$input['label'], $input['accompaniment_conditions']] = ['DIPERTIMBANGKAN', null],
            'g7' => $input['unresolved_g7_aspects'] = ['C4'],
            'override' => $input['overrides'] = [['type' => 'level', 'aspect' => 'A1', 'reason' => 'kurang']],
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
}
