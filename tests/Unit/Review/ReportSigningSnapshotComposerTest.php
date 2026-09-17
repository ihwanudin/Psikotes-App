<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Domain\Review\G7AspectResolution;
use App\Domain\Review\G7ReviewSet;
use App\Domain\Review\ProfessionalOverridePolicy;
use App\Domain\Review\ReportSigningSnapshotComposer;
use App\Domain\Review\ReviewedEligibilityDecision;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

final class ReportSigningSnapshotComposerTest extends TestCase
{
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    public function test_it_derives_signing_prerequisites_from_typed_review_artifacts(): void
    {
        $reason = 'Observasi profesional mendukung level akhir tiga.';
        $levelOverride = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => 'C4', 'system_level' => 5, 'final_level' => 3, 'reason' => $reason,
        ]);
        $reviewed = ReviewedEligibilityDecision::create($this->baseline(), [$levelOverride]);
        $g7 = $this->g7Set($reviewed, ['C4' => ['final_level' => 3, 'reason' => $reason]]);

        $snapshot = ReportSigningSnapshotComposer::compose($reviewed, $g7, $this->structuralInput());

        self::assertSame('V1', $snapshot->prerequisiteInput()['validity']);
        self::assertSame('DIPERTIMBANGKAN', $snapshot->prerequisiteInput()['label']);
        self::assertSame('KAIGO', $snapshot->prerequisiteInput()['target_field']);
        self::assertSame([], $snapshot->prerequisiteInput()['unresolved_g7_aspects']);
        self::assertSame([['type' => 'level', 'aspect' => 'C4', 'reason' => $reason]], $snapshot->prerequisiteInput()['overrides']);
        self::assertSame(self::ASPECTS, $snapshot->provenance()['discrepancy_aspects']);
        self::assertSame($reviewed->toArray(), $snapshot->provenance()['reviewed_eligibility']);
        self::assertCount(18, $snapshot->provenance()['g7_review']);
        self::assertSame([
            'aspect' => 'C4',
            'state' => G7AspectResolution::STATE_RESOLVED,
            'discrepancy' => $g7->resolutionFor('C4')->discrepancy(),
            'system_level' => 5,
            'final_level' => 3,
            'reason' => $reason,
        ], $snapshot->provenance()['g7_review'][9]);
        self::assertFalse($snapshot->provenance()['persistence_authority_bound']);
    }

    public function test_not_required_g7_does_not_block_a_non_g7_professional_override(): void
    {
        $override = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => 'A2', 'system_level' => 5, 'final_level' => 4,
            'reason' => 'Observasi profesional mendukung level akhir empat.',
        ]);
        $reviewed = ReviewedEligibilityDecision::create($this->baseline(), [$override]);
        $snapshot = ReportSigningSnapshotComposer::compose($reviewed, $this->g7Set($reviewed), $this->structuralInput());

        self::assertSame(4, $reviewed->toArray()['final_levels']['A2']);
        self::assertSame([], $snapshot->prerequisiteInput()['unresolved_g7_aspects']);
    }

    public function test_g7_system_level_must_match_the_reviewed_system_level(): void
    {
        $reviewed = ReviewedEligibilityDecision::create($this->baseline(), []);
        $resolutions = $this->notRequiredResolutions($reviewed);
        $resolutions[0] = G7AspectResolution::notRequired($this->discrepancy('A1', [4]), 4);

        $this->expectException(InvalidArgumentException::class);
        ReportSigningSnapshotComposer::compose($reviewed, G7ReviewSet::fromResolutions($resolutions), $this->structuralInput());
    }

    public function test_resolved_g7_final_level_must_match_the_recalculated_final_level(): void
    {
        $reviewed = ReviewedEligibilityDecision::create($this->baseline(), []);

        $this->expectException(InvalidArgumentException::class);
        ReportSigningSnapshotComposer::compose($reviewed, $this->g7Set($reviewed, [
            'C4' => ['final_level' => 3, 'reason' => 'Observasi profesional mendukung level akhir tiga.'],
        ]), $this->structuralInput());
    }

    public function test_changed_g7_reason_must_match_the_reviewed_level_override(): void
    {
        $override = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => 'C4', 'system_level' => 5, 'final_level' => 3,
            'reason' => 'Observasi profesional mendukung level akhir tiga.',
        ]);
        $reviewed = ReviewedEligibilityDecision::create($this->baseline(), [$override]);

        $this->expectException(InvalidArgumentException::class);
        ReportSigningSnapshotComposer::compose($reviewed, $this->g7Set($reviewed, [
            'C4' => ['final_level' => 3, 'reason' => 'Alasan berbeda tetapi tetap memenuhi dua puluh karakter.'],
        ]), $this->structuralInput());
    }

    public function test_v3_stays_blocked_without_a_label(): void
    {
        $reviewed = ReviewedEligibilityDecision::create($this->baseline('V3'), []);
        $snapshot = ReportSigningSnapshotComposer::compose($reviewed, $this->g7Set($reviewed), $this->structuralInput());

        self::assertSame('V3', $snapshot->prerequisiteInput()['validity']);
        self::assertNull($snapshot->prerequisiteInput()['label']);
    }

    public function test_raw_reviewed_array_with_a_forged_recommendation_is_rejected(): void
    {
        $reviewed = ReviewedEligibilityDecision::create($this->baseline(), []);
        $raw = $reviewed->toArray();
        $raw['final_decision']['label'] = 'TIDAK_DISARANKAN';

        $this->expectException(TypeError::class);
        /** @phpstan-ignore argument.type */
        ReportSigningSnapshotComposer::compose($raw, $this->g7Set($reviewed), $this->structuralInput());
    }

    public function test_raw_stale_critical_label_payload_is_rejected(): void
    {
        $reviewed = ReviewedEligibilityDecision::create($this->baseline(), []);
        $raw = $reviewed->toArray();
        $raw['final_levels']['C4'] = 1;

        $this->expectException(TypeError::class);
        /** @phpstan-ignore argument.type */
        ReportSigningSnapshotComposer::compose($raw, $this->g7Set($reviewed), $this->structuralInput());
    }

    public function test_boolean_only_g7_payload_is_rejected(): void
    {
        $reviewed = ReviewedEligibilityDecision::create($this->baseline(), []);

        $this->expectException(TypeError::class);
        /** @phpstan-ignore argument.type */
        ReportSigningSnapshotComposer::compose($reviewed, ['review_resolved' => true], $this->structuralInput());
    }

    public function test_raw_evidence_keys_are_rejected_from_the_structural_input(): void
    {
        $reviewed = ReviewedEligibilityDecision::create($this->baseline(), []);

        $this->expectException(InvalidArgumentException::class);
        ReportSigningSnapshotComposer::compose($reviewed, $this->g7Set($reviewed), [
            ...$this->structuralInput(), 'audit_recorded' => true, 'recalculation_completed' => true,
        ]);
    }

    public function test_snapshot_hash_binds_levels_source_versions_and_g7_evidence(): void
    {
        $baseline = ReviewedEligibilityDecision::create($this->baseline(), []);
        $canonical = ReportSigningSnapshotComposer::compose(
            $baseline,
            $this->g7Set($baseline),
            $this->structuralInput(),
        );

        $differentLevels = ReviewedEligibilityDecision::create($this->baseline(level: 4), []);
        $levelSnapshot = ReportSigningSnapshotComposer::compose(
            $differentLevels,
            $this->g7Set($differentLevels),
            $this->structuralInput(),
        );
        $differentVersion = ReviewedEligibilityDecision::create($this->baseline(istVersion: 'F0-2026.09'), []);
        $versionSnapshot = ReportSigningSnapshotComposer::compose(
            $differentVersion,
            $this->g7Set($differentVersion),
            $this->structuralInput(),
        );
        $g7Snapshot = ReportSigningSnapshotComposer::compose(
            $baseline,
            $this->g7Set($baseline, sourcePrefix: 'ALTERNATE_SOURCE'),
            $this->structuralInput(),
        );

        self::assertNotSame($this->snapshotHash($canonical), $this->snapshotHash($levelSnapshot));
        self::assertNotSame($this->snapshotHash($canonical), $this->snapshotHash($versionSnapshot));
        self::assertNotSame($this->snapshotHash($canonical), $this->snapshotHash($g7Snapshot));
    }

    public function test_semantically_identical_permutations_have_identical_snapshot_arrays_and_hashes(): void
    {
        $canonicalReviewed = ReviewedEligibilityDecision::create($this->baseline(), []);
        $canonical = ReportSigningSnapshotComposer::compose(
            $canonicalReviewed,
            G7ReviewSet::fromResolutions($this->notRequiredResolutions($canonicalReviewed, 'SOURCE', true)),
            $this->structuralInput(),
        );

        $permutedReviewed = ReviewedEligibilityDecision::create($this->baseline(reverseLevels: true), []);
        $resolutions = array_reverse($this->notRequiredResolutions($permutedReviewed, 'SOURCE', true));
        $structural = $this->structuralInput();
        $structural['narrative_clusters'] = array_reverse($structural['narrative_clusters'], true);
        $structural = array_reverse($structural, true);
        $permuted = ReportSigningSnapshotComposer::compose(
            $permutedReviewed,
            G7ReviewSet::fromResolutions($resolutions),
            $structural,
        );

        self::assertSame($canonical->prerequisiteInput(), $permuted->prerequisiteInput());
        self::assertSame($canonical->provenance(), $permuted->provenance());
        self::assertSame($this->snapshotHash($canonical), $this->snapshotHash($permuted));
    }

    private function baseline(
        string $validity = 'V1',
        int $level = 5,
        string $istVersion = 'F0-2026.08',
        bool $reverseLevels = false,
    ): EligibilityDecisionSnapshot {
        $reporting = $this->canonicalReporting();
        $aspects = $reverseLevels ? array_reverse(self::ASPECTS) : self::ASPECTS;

        return EligibilityDecisionSnapshot::create([
            'levels' => array_fill_keys($aspects, $level), 'field_code' => 'KAIGO', 'iq' => 100,
            'validity' => $validity, 'standard_configuration' => $reporting,
            'eligibility_source_versions' => [
                'ist' => $istVersion, 'papi' => 'F0-2026.08', 'kraepelin' => 'F0-2026.08',
                'rmib' => 'F0-2026.08', 'reporting' => $reporting['standard_version'],
            ],
        ]);
    }

    /** @param array<string, array{final_level: int, reason: string|null}> $resolved */
    private function g7Set(
        ReviewedEligibilityDecision $reviewed,
        array $resolved = [],
        string $sourcePrefix = 'SOURCE',
    ): G7ReviewSet {
        $resolutions = $this->notRequiredResolutions($reviewed, $sourcePrefix);
        foreach ($resolved as $aspect => $decision) {
            $index = array_search($aspect, self::ASPECTS, true);
            self::assertIsInt($index);
            $systemLevel = $reviewed->toArray()['system_levels'][$aspect];
            $resolutions[$index] = G7AspectResolution::resolved(
                $this->discrepancy($aspect, [2, 4]), $systemLevel, $decision['final_level'], $decision['reason'],
            );
        }

        return G7ReviewSet::fromResolutions($resolutions);
    }

    /** @return list<G7AspectResolution> */
    private function notRequiredResolutions(
        ReviewedEligibilityDecision $reviewed,
        string $sourcePrefix = 'SOURCE',
        bool $reverseSources = false,
    ): array {
        $levels = $reviewed->toArray()['system_levels'];

        return array_map(fn (string $aspect): G7AspectResolution => G7AspectResolution::notRequired(
            (new AspectSourceDiscrepancyPolicy)->evaluate([
                'aspect' => $aspect,
                'sources' => $reverseSources
                    ? [
                        ['source' => $sourcePrefix.'_2', 'level' => $levels[$aspect]],
                        ['source' => $sourcePrefix.'_1', 'level' => $levels[$aspect]],
                    ]
                    : [['source' => $sourcePrefix.'_1', 'level' => $levels[$aspect]]],
            ]),
            $levels[$aspect],
        ), self::ASPECTS);
    }

    private function snapshotHash(ReportSigningSnapshotComposer $snapshot): string
    {
        return hash('sha256', json_encode([
            'prerequisite_input' => $snapshot->prerequisiteInput(),
            'provenance' => $snapshot->provenance(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<int>  $levels
     * @return array<mixed>
     */
    private function discrepancy(string $aspect, array $levels): array
    {
        return (new AspectSourceDiscrepancyPolicy)->evaluate([
            'aspect' => $aspect,
            'sources' => array_map(
                static fn (int $level, int $index): array => ['source' => 'SOURCE_'.($index + 1), 'level' => $level],
                $levels, array_keys($levels),
            ),
        ]);
    }

    /** @return array<mixed> */
    private function structuralInput(): array
    {
        return [
            'procedure_note' => null,
            'accompaniment_conditions' => 'Pendampingan diberikan pada masa adaptasi kerja.',
            'narrative_clusters' => [
                'A' => 'Kemampuan umum telah dirangkum.', 'B' => 'Cara kerja telah dirangkum.',
                'C' => 'Kepribadian telah dirangkum.', 'D' => 'Minat kerja telah dirangkum.',
            ],
        ];
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
