<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Domain\Review\ReportSigningPrerequisitePolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportSigningPrerequisitePolicyTest extends TestCase
{
    public function test_complete_v1_report_can_be_signed(): void
    {
        $result = (new ReportSigningPrerequisitePolicy)->evaluate($this->validInput());

        $this->assertTrue($result['can_sign']);
        $this->assertSame([], $result['blocking_reason_codes']);
        $this->assertSame([
            'validity' => 'V1',
            'label' => 'DISARANKAN',
            'procedure_note_present' => false,
            'accompaniment_conditions_present' => false,
            'unresolved_g7_aspects' => [],
            'overrides' => [],
            'target_field' => 'KAIGO',
            'narrative_clusters_present' => ['A' => true, 'B' => true, 'C' => true, 'D' => true],
        ], $result['provenance']);
    }

    public function test_complete_v2_and_considered_report_can_be_signed(): void
    {
        $input = $this->validInput();
        $input['validity'] = 'V2';
        $input['procedure_note'] = 'Koneksi sempat terputus dan telah ditinjau.';
        $input['label'] = 'DIPERTIMBANGKAN';
        $input['accompaniment_conditions'] = 'Pendampingan kerja pada masa adaptasi awal.';
        $input['overrides'] = [
            ['type' => 'label', 'aspect' => null, 'reason' => 'Pertimbangan profesional telah dicatat lengkap.'],
            ['type' => 'level', 'aspect' => 'C2', 'reason' => 'Observasi mendukung penyesuaian level akhir.'],
        ];

        $result = (new ReportSigningPrerequisitePolicy)->evaluate($input);

        $this->assertTrue($result['can_sign']);
        $this->assertSame([], $result['blocking_reason_codes']);
        $this->assertSame([
            ['type' => 'label', 'aspect' => null, 'reason_character_count' => 47],
            ['type' => 'level', 'aspect' => 'C2', 'reason_character_count' => 44],
        ], $result['provenance']['overrides']);
    }

    #[DataProvider('individualBlockers')]
    public function test_each_unmet_prerequisite_returns_its_blocking_reason(string $mutation, string $expectedCode): void
    {
        $input = $this->validInput();

        switch ($mutation) {
            case 'v3':
                $input['validity'] = 'V3';
                break;
            case 'v2_note':
                $input['validity'] = 'V2';
                $input['procedure_note'] = null;
                break;
            case 'conditions':
                $input['label'] = 'DIPERTIMBANGKAN';
                $input['accompaniment_conditions'] = '';
                break;
            case 'g7':
                $input['unresolved_g7_aspects'] = ['C4'];
                break;
            case 'override_reason':
                $input['overrides'] = [['type' => 'level', 'aspect' => 'A2', 'reason' => '1234567890123456789']];
                break;
            case 'field':
                $input['target_field'] = null;
                break;
            default:
                $input['narrative_clusters'][$mutation] = '   ';
        }

        $result = (new ReportSigningPrerequisitePolicy)->evaluate($input);

        $this->assertFalse($result['can_sign']);
        $this->assertSame([$expectedCode], $result['blocking_reason_codes']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function individualBlockers(): iterable
    {
        yield 'V3 blocks signing' => ['v3', 'VALIDITY_V3'];
        yield 'V2 needs procedure note' => ['v2_note', 'V2_PROCEDURE_NOTE_REQUIRED'];
        yield 'considered needs conditions' => ['conditions', 'ACCOMPANIMENT_CONDITIONS_REQUIRED'];
        yield 'G7 must be resolved' => ['g7', 'G7_ASPECTS_UNRESOLVED'];
        yield 'override reason needs 20 characters' => ['override_reason', 'OVERRIDE_REASON_MIN_LENGTH'];
        yield 'target field is required' => ['field', 'TARGET_FIELD_REQUIRED'];
        yield 'cluster A is required' => ['A', 'NARRATIVE_CLUSTER_A_REQUIRED'];
        yield 'cluster B is required' => ['B', 'NARRATIVE_CLUSTER_B_REQUIRED'];
        yield 'cluster C is required' => ['C', 'NARRATIVE_CLUSTER_C_REQUIRED'];
        yield 'cluster D is required' => ['D', 'NARRATIVE_CLUSTER_D_REQUIRED'];
    }

    public function test_combined_blockers_use_stable_spec_order(): void
    {
        $input = $this->validInput();
        $input['validity'] = 'V2';
        $input['procedure_note'] = " \t\n ";
        $input['label'] = 'DIPERTIMBANGKAN';
        $input['accompaniment_conditions'] = ' ';
        $input['unresolved_g7_aspects'] = ['D3', 'A2'];
        $input['overrides'] = [['type' => 'label', 'aspect' => null, 'reason' => 'terlalu singkat']];
        $input['target_field'] = '';
        $input['narrative_clusters'] = ['A' => '', 'B' => ' ', 'C' => "\n", 'D' => null];

        $result = (new ReportSigningPrerequisitePolicy)->evaluate($input);

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
        $this->assertSame(['A2', 'D3'], $result['provenance']['unresolved_g7_aspects']);
    }

    public function test_override_reason_boundary_is_unicode_character_aware(): void
    {
        $atBoundary = $this->validInput();
        $atBoundary['overrides'] = [['type' => 'level', 'aspect' => 'A1', 'reason' => '12345678901234567890']];
        $unicode = $this->validInput();
        $unicode['overrides'] = [['type' => 'label', 'aspect' => null, 'reason' => 'Alasan akhir: 日本語で十分です']];

        $policy = new ReportSigningPrerequisitePolicy;

        $this->assertTrue($policy->evaluate($atBoundary)['can_sign']);
        $this->assertTrue($policy->evaluate($unicode)['can_sign']);
    }

    /** @param array<mixed> $input */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_extra_or_duplicate_input_fails_closed(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ReportSigningPrerequisitePolicy)->evaluate($input);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidPayloads(): iterable
    {
        $valid = self::validInputFixture();

        $missing = $valid;
        unset($missing['label']);
        yield 'missing top-level key' => [$missing];
        yield 'extra top-level key' => [[...$valid, 'signed' => false]];
        yield 'unknown validity' => [[...$valid, 'validity' => 'V4']];
        yield 'unknown label' => [[...$valid, 'label' => 'LAINNYA']];
        yield 'unknown target field' => [[...$valid, 'target_field' => 'TOKUTEI']];
        yield 'invalid procedure note type' => [[...$valid, 'procedure_note' => 1]];
        yield 'invalid condition type' => [[...$valid, 'accompaniment_conditions' => []]];
        yield 'G7 aspects must be a list' => [[...$valid, 'unresolved_g7_aspects' => ['first' => 'A1']]];
        yield 'duplicate G7 aspect' => [[...$valid, 'unresolved_g7_aspects' => ['A1', 'A1']]];
        yield 'unknown G7 aspect' => [[...$valid, 'unresolved_g7_aspects' => ['A3']]];
        yield 'overrides must be a list' => [[...$valid, 'overrides' => ['first' => ['type' => 'label', 'aspect' => null, 'reason' => null]]]];
        yield 'duplicate level override' => [[...$valid, 'overrides' => [
            ['type' => 'level', 'aspect' => 'A1', 'reason' => null],
            ['type' => 'level', 'aspect' => 'A1', 'reason' => null],
        ]]];
        yield 'duplicate label override' => [[...$valid, 'overrides' => [
            ['type' => 'label', 'aspect' => null, 'reason' => null],
            ['type' => 'label', 'aspect' => null, 'reason' => null],
        ]]];
        yield 'unknown override type' => [[...$valid, 'overrides' => [['type' => 'zone', 'aspect' => 'A1', 'reason' => null]]]];
        yield 'override reason must be string or null' => [[...$valid, 'overrides' => [['type' => 'level', 'aspect' => 'A1', 'reason' => 20]]]];
        yield 'level override needs aspect' => [[...$valid, 'overrides' => [['type' => 'level', 'aspect' => null, 'reason' => null]]]];
        yield 'label override cannot have aspect' => [[...$valid, 'overrides' => [['type' => 'label', 'aspect' => 'A1', 'reason' => null]]]];
        yield 'override has extra key' => [[...$valid, 'overrides' => [[
            'type' => 'level', 'aspect' => 'A1', 'reason' => null, 'final_level' => 4,
        ]]]];
        yield 'narrative clusters need exact keys' => [[...$valid, 'narrative_clusters' => ['A' => 'a', 'B' => 'b', 'C' => 'c']]];
        yield 'narrative cluster must be string or null' => [[...$valid, 'narrative_clusters' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 4]]];
    }

    /** @return array<mixed> */
    private function validInput(): array
    {
        return self::validInputFixture();
    }

    /** @return array<mixed> */
    private static function validInputFixture(): array
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
