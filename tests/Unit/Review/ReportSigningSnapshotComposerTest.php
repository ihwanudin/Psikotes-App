<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Domain\Review\ProfessionalOverridePolicy;
use App\Domain\Review\ReportSigningSnapshotComposer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportSigningSnapshotComposerTest extends TestCase
{
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    public function test_it_derives_prerequisites_from_typed_policy_outputs(): void
    {
        $input = $this->validInput();
        $input['discrepancies'][3] = $this->discrepancy('B2', [1, 3], false);
        $input['overrides'] = [
            $this->overrideEvidence(
                (new ProfessionalOverridePolicy)->levelOverride([
                    'aspect' => 'A1',
                    'system_level' => 2,
                    'final_level' => 3,
                    'reason' => 'Observasi profesional mendukung level akhir tiga.',
                ]),
                true,
                true,
            ),
            $this->overrideEvidence(
                (new ProfessionalOverridePolicy)->labelOverride([
                    'system_label' => 'DISARANKAN',
                    'final_label' => 'DIPERTIMBANGKAN',
                    'reason' => 'Pendampingan profesional diperlukan selama masa adaptasi.',
                ]),
                true,
                false,
            ),
            $this->overrideEvidence(
                (new ProfessionalOverridePolicy)->levelOverride([
                    'aspect' => 'C2',
                    'system_level' => 3,
                    'final_level' => 3,
                    'reason' => null,
                ]),
                false,
                false,
            ),
        ];

        $snapshot = ReportSigningSnapshotComposer::compose($input);

        self::assertSame([
            'validity' => 'V1',
            'procedure_note' => null,
            'label' => 'DIPERTIMBANGKAN',
            'accompaniment_conditions' => 'Pendampingan diberikan pada masa adaptasi kerja.',
            'unresolved_g7_aspects' => ['B2'],
            'overrides' => [
                ['type' => 'label', 'aspect' => null, 'reason' => 'Pendampingan profesional diperlukan selama masa adaptasi.'],
                ['type' => 'level', 'aspect' => 'A1', 'reason' => 'Observasi profesional mendukung level akhir tiga.'],
            ],
            'target_field' => 'KAIGO',
            'narrative_clusters' => $input['narrative_clusters'],
        ], $snapshot->prerequisiteInput());
        self::assertSame('report_signing_snapshot', $snapshot->provenance()['type']);
        self::assertSame(self::ASPECTS, $snapshot->provenance()['discrepancy_aspects']);
        self::assertSame(2, $snapshot->provenance()['changed_override_count']);
    }

    public function test_resolved_g7_is_derived_from_its_discrepancy_evidence(): void
    {
        $input = $this->validInput();
        $input['discrepancies'][9] = $this->discrepancy('C4', [2, 4], true);

        $snapshot = ReportSigningSnapshotComposer::compose($input);

        self::assertSame([], $snapshot->prerequisiteInput()['unresolved_g7_aspects']);
    }

    public function test_v3_recommendation_projects_a_null_label(): void
    {
        $input = $this->validInput();
        $input['recommendation'] = $this->v3Recommendation();

        $snapshot = ReportSigningSnapshotComposer::compose($input);

        self::assertSame('V3', $snapshot->prerequisiteInput()['validity']);
        self::assertNull($snapshot->prerequisiteInput()['label']);
    }

    public function test_internally_inconsistent_recommendation_output_fails_closed(): void
    {
        $input = $this->validInput();
        $input['recommendation']['provenance']['critical_belum_aspects'] = ['A1'];
        $input['recommendation']['provenance']['belum_aspects'] = ['A1'];
        $input['recommendation']['provenance']['zone_counts'] = ['OK' => 17, 'GREY' => 0, 'BELUM' => 1, 'UNASSESSED' => 0];

        $this->expectException(InvalidArgumentException::class);
        ReportSigningSnapshotComposer::compose($input);
    }

    public function test_changed_override_requires_audit_evidence(): void
    {
        $input = $this->validInput();
        $input['overrides'] = [$this->overrideEvidence(
            (new ProfessionalOverridePolicy)->labelOverride([
                'system_label' => 'DISARANKAN',
                'final_label' => 'DIPERTIMBANGKAN',
                'reason' => 'Pertimbangan profesional telah dicatat secara lengkap.',
            ]),
            false,
            false,
        )];

        $this->expectException(InvalidArgumentException::class);
        ReportSigningSnapshotComposer::compose($input);
    }

    public function test_changed_level_override_requires_recalculation_evidence(): void
    {
        $input = $this->validInput();
        $input['overrides'] = [$this->overrideEvidence(
            (new ProfessionalOverridePolicy)->levelOverride([
                'aspect' => 'C4',
                'system_level' => 2,
                'final_level' => 4,
                'reason' => 'Observasi profesional mendukung perubahan level akhir.',
            ]),
            true,
            false,
        )];

        $this->expectException(InvalidArgumentException::class);
        ReportSigningSnapshotComposer::compose($input);
    }

    public function test_label_override_must_start_from_the_recommendation_label(): void
    {
        $input = $this->validInput();
        $input['overrides'] = [$this->overrideEvidence(
            (new ProfessionalOverridePolicy)->labelOverride([
                'system_label' => 'TIDAK_DISARANKAN',
                'final_label' => 'DIPERTIMBANGKAN',
                'reason' => 'Pertimbangan profesional telah dicatat secara lengkap.',
            ]),
            true,
            false,
        )];

        $this->expectException(InvalidArgumentException::class);
        ReportSigningSnapshotComposer::compose($input);
    }

    public function test_exactly_eighteen_unique_discrepancy_outputs_are_required(): void
    {
        $missing = $this->validInput();
        array_pop($missing['discrepancies']);
        $duplicate = $this->validInput();
        $duplicate['discrepancies'][17] = $duplicate['discrepancies'][0];

        foreach ([$missing, $duplicate] as $input) {
            try {
                ReportSigningSnapshotComposer::compose($input);
                self::fail('Invalid discrepancy coverage was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_extra_top_level_input_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportSigningSnapshotComposer::compose([...$this->validInput(), 'unresolved_g7_aspects' => []]);
    }

    /** @return array<mixed> */
    private function validInput(): array
    {
        return [
            'recommendation' => $this->v1Recommendation(),
            'discrepancies' => array_map(fn (string $aspect): array => $this->discrepancy($aspect, [3], false), self::ASPECTS),
            'overrides' => [],
            'procedure_note' => null,
            'accompaniment_conditions' => 'Pendampingan diberikan pada masa adaptasi kerja.',
            'target_field' => 'KAIGO',
            'narrative_clusters' => [
                'A' => 'Kemampuan umum telah dirangkum.',
                'B' => 'Cara kerja telah dirangkum.',
                'C' => 'Kepribadian telah dirangkum.',
                'D' => 'Minat kerja telah dirangkum.',
            ],
        ];
    }

    /**
     * @param  list<int>  $levels
     * @return array<mixed>
     */
    private function discrepancy(string $aspect, array $levels, bool $reviewResolved): array
    {
        $sources = [];
        foreach ($levels as $index => $level) {
            $sources[] = ['source' => 'SOURCE_'.($index + 1), 'level' => $level];
        }

        return [
            'result' => (new AspectSourceDiscrepancyPolicy)->evaluate(['aspect' => $aspect, 'sources' => $sources]),
            'review_resolved' => $reviewResolved,
        ];
    }

    /**
     * @param  array<mixed>  $result
     * @return array<mixed>
     */
    private function overrideEvidence(array $result, bool $auditRecorded, bool $recalculationCompleted): array
    {
        return [
            'result' => $result,
            'audit_recorded' => $auditRecorded,
            'recalculation_completed' => $recalculationCompleted,
        ];
    }

    /** @return array<mixed> */
    private function v1Recommendation(): array
    {
        return [
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
                'validity' => 'V1',
                'critical_belum_aspects' => [],
                'belum_aspects' => [],
                'grey_aspects' => [],
                'zone_counts' => ['OK' => 18, 'GREY' => 0, 'BELUM' => 0, 'UNASSESSED' => 0],
            ],
        ];
    }

    /** @return array<mixed> */
    private function v3Recommendation(): array
    {
        return [
            'type' => 'publication_blocked',
            'publication_blocked' => true,
            'reason_code' => 'VALIDITY_V3',
            'provenance' => [
                'standard_version' => 'GA-2026.08',
                'field_code' => 'KAIGO',
                'iq' => 100,
                'validity' => 'V3',
            ],
        ];
    }
}
