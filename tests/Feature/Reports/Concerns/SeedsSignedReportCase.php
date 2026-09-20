<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Concerns;

use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentCase;
use App\Models\Branch;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Services\ReportRendering\ReportSupplementalData;
use App\Services\Review\ReportSigningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synthetic signed-report fixtures built through the real F5 signing
 * service, so the F6 adapter is exercised against the persisted snapshot
 * shape rather than a hand-written copy of it.
 */
trait SeedsSignedReportCase
{
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    private const IST_VERSION = 'synthetic-report-ist-v1';

    private function createReportCase(?string $testNumber = 'T26-09-9001', string $field = 'UMUM'): AssessmentCase
    {
        $suffix = Str::lower(Str::random(8));
        $branch = Branch::query()->create([
            'code' => 'BR-RG-'.$suffix,
            'name' => 'Cabang Sintetis Laporan',
            'ref_code' => 'REF-RG-'.$suffix,
            // organization_code/display_name are NOT NULL on PostgreSQL
            // (2026_08_29_000200 addPostgresControls); SQLite doesn't
            // enforce this, which is why the gap wasn't caught until this
            // trait ran against the real Postgres harness.
            'organization_code' => 'BR-RG-'.$suffix,
            'display_name' => 'Cabang Sintetis Laporan',
        ]);
        $package = TestPackage::query()->create([
            'code' => 'PKG-RG-'.$suffix,
            'name' => 'Paket Sintetis Laporan',
            'amount' => 250_000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $package->items()->create(['test_type' => 'ist']);
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'package_id' => $package->id,
            'source_system' => 'DIRECT_PUBLIC',
            'full_name' => 'Peserta Sintetis Laporan',
            'gender' => 'female',
            'birth_date' => '2002-03-04',
            'education_level' => 'SMA/SMK',
            'intended_field' => $field,
            'phone' => '+6281200000000',
        ]);
        DB::table('participants')->where('id', $participant->id)->update(['test_number' => $testNumber]);

        return AssessmentCase::query()->create([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant->id,
            'organization_id' => $branch->id,
            'package_id' => $package->id,
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => $field,
        ]);
    }

    private function reportPsychologist(): Admin
    {
        return Admin::query()->create([
            'name' => 'Psikolog Sintetis',
            'email' => (string) Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => AdminRole::Psychologist->value,
        ]);
    }

    /**
     * Seeds eligibility + narrative baselines and signs through the real
     * ReportSigningService.
     *
     * @param  array<string, int>  $levels
     * @param  list<array{aspect: string, system_level: int, final_level: int, reason: string}>  $levelOverrides
     */
    private function signCase(
        AssessmentCase $case,
        Admin $psychologist,
        array $levels = [],
        int $iq = 110,
        string $field = 'UMUM',
        array $levelOverrides = [],
    ): string {
        $this->seedIstInstrumentVersion();
        $levels = [...array_fill_keys(self::ASPECTS, 4), ...$levels];
        $reporting = $this->canonicalReporting();
        $canonicalInput = [
            'levels' => $levels,
            'field_code' => $field,
            'iq' => $iq,
            'validity' => 'V1',
            'standard_configuration' => $reporting,
            'eligibility_source_versions' => [
                'ist' => self::IST_VERSION, 'papi' => 'F0-2026.08', 'kraepelin' => 'F0-2026.08',
                'rmib' => 'F0-2026.08', 'reporting' => $reporting['standard_version'],
            ],
        ];
        $snapshot = EligibilityDecisionSnapshot::create($canonicalInput)->toArray();

        $eligibilityId = (string) Str::ulid();
        DB::table('eligibility_decision_versions')->insert([
            'id' => $eligibilityId,
            'assessment_case_id' => $case->id,
            'version' => 1,
            'supersedes_id' => null,
            'standard_version' => $snapshot['provenance']['eligibility_standard_version'],
            'field_code' => $snapshot['zone']['field_code'],
            'publication_blocked' => $snapshot['publication_blocked'],
            'recommendation_label' => $snapshot['recommendation']['label'] ?? null,
            'iq' => $iq,
            'validity' => 'V1',
            'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'canonical_input_json' => json_encode($canonicalInput, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $narrativeId = (string) Str::ulid();
        DB::table('bilingual_narrative_versions')->insert([
            'id' => $narrativeId,
            'assessment_case_id' => $case->id,
            'version' => 1,
            'supersedes_id' => null,
            'eligibility_version_id' => $eligibilityId,
            'review_required' => false,
            'cluster_a_id' => 'A', 'cluster_a_jp' => 'A',
            'cluster_b_id' => 'B', 'cluster_b_jp' => 'B',
            'cluster_c_id' => 'C', 'cluster_c_jp' => 'C',
            'cluster_d_id' => 'D', 'cluster_d_jp' => 'D',
            'snapshot_json' => json_encode(['type' => 'bilingual_cluster_narratives'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $label = $snapshot['recommendation']['label'] ?? null;
        $result = app(ReportSigningService::class)->sign($case->public_id, $psychologist, [
            'eligibility_version_id' => $eligibilityId,
            'narrative_version_id' => $narrativeId,
            'level_overrides' => $levelOverrides,
            'label_override' => null,
            'g7_resolutions' => [],
            'procedure_note' => null,
            'accompaniment_conditions' => $label === 'DISARANKAN' ? null : 'Pendampingan komunikasi bulan pertama.',
            'narrative_clusters' => [
                'A' => 'Narasi sintetis klaster A.',
                'B' => 'Narasi sintetis klaster B.',
                'C' => 'Narasi sintetis klaster C.',
                'D' => 'Narasi sintetis klaster D.',
            ],
        ]);
        if ($result['success'] !== true) {
            throw new \RuntimeException('Synthetic signing failed: '.$result['code'].' '.$result['message']);
        }

        return (string) $result['data']['id'];
    }

    private function seedSubmittedSession(AssessmentCase $case, string $submittedAt = '2026-09-13 04:20:00'): void
    {
        $source = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1', 'provenance' => 'synthetic-report-test-only',
            'total_duration_seconds' => 540,
            'subtests' => array_map(static fn (string $code): array => ['code' => $code, 'duration_seconds' => 60, 'item_count' => 1], ['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME']),
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([...$source, 'checksum' => SessionDefinition::checksumFor($source)]);

        DB::table('test_sessions')->insert([
            'public_id' => (string) Str::ulid(), 'participant_id' => $case->participant_id, 'assessment_case_id' => $case->id,
            'test_type' => 'ist', 'attempt_no' => 1, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $definition->totalDurationSeconds, 'status' => 'submitted',
            'answers_revision' => 1, 'started_at' => '2026-09-13 04:00:00', 'ends_at' => '2026-09-13 04:30:00',
            'submitted_at' => $submittedAt,
            'session_definition_version' => $definition->version, 'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum, 'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => '2026-09-13 04:00:00', 'updated_at' => $submittedAt,
        ]);
    }

    /**
     * Subscale categories use sentinels so tests can prove they never reach
     * the HPP.
     */
    private function seedDassResult(
        AssessmentCase $case,
        string $overallCategory,
        ?string $completedAt,
        string $status = 'completed',
    ): void {
        $assessmentId = DB::table('dass_assessments')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $case->participant_id,
            'status' => $status,
            'started_at' => $completedAt,
            'completed_at' => $completedAt,
            'expires_at' => now()->addYear(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('dass_results')->insert([
            'assessment_id' => $assessmentId,
            'depression_raw' => 7, 'anxiety_raw' => 5, 'stress_raw' => 9,
            'depression_score' => 14, 'anxiety_score' => 10, 'stress_score' => 18,
            'depression_category' => 'SECRET-DEPRESSION',
            'anxiety_category' => 'SECRET-ANXIETY',
            'stress_category' => 'SECRET-STRESS',
            'overall_category' => $overallCategory,
            'follow_up' => 'monitoring',
            'expires_at' => now()->addYear(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedIstInstrumentVersion(): void
    {
        if (DB::table('instrument_versions')->where('code', 'ist')->where('version', self::IST_VERSION)->exists()) {
            return;
        }

        $payload = json_encode([
            'version' => self::IST_VERSION,
            'iq_level_bands' => $this->istData()['iq_level_bands'],
        ], JSON_THROW_ON_ERROR);

        DB::table('instrument_versions')->insert([
            'code' => 'ist', 'version' => self::IST_VERSION, 'source_file' => 'synthetic-report-ist.json',
            'checksum' => hash('sha256', $payload), 'payload' => $payload, 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function istData(): array
    {
        return json_decode((string) file_get_contents(base_path('database/seeders/data/ist.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{standard_version: string, base_standards: array<mixed>, fields: array<mixed>} */
    private function canonicalReporting(): array
    {
        $data = json_decode((string) file_get_contents(base_path('database/seeders/data/reporting.json')), true, flags: JSON_THROW_ON_ERROR);

        return [
            'standard_version' => $data['standard_version'],
            'base_standards' => $data['base_standards'],
            'fields' => $data['fields'],
        ];
    }

    /** Like completeSupplementalData(), but DASS text carries no follow-up (Normal/Ringan). */
    private function supplementalWithoutDassFollowUp(): ReportSupplementalData
    {
        $complete = $this->completeSupplementalData();

        return new class($complete) implements ReportSupplementalData
        {
            public function __construct(private ReportSupplementalData $inner) {}

            public function reportNumber(int $assessmentCaseId, string $snapshotId): string
            {
                return (string) $this->inner->reportNumber($assessmentCaseId, $snapshotId);
            }

            public function psychologistSippNumber(int $adminId): string
            {
                return (string) $this->inner->psychologistSippNumber($adminId);
            }

            public function recommendationRationale(string $snapshotId): string
            {
                return (string) $this->inner->recommendationRationale($snapshotId);
            }

            public function aspectLabels(string $standardVersion): array
            {
                return (array) $this->inner->aspectLabels($standardVersion);
            }

            public function dassScreeningText(string $generalCategory): array
            {
                return ['narrative' => "Skrining kategori umum {$generalCategory}.", 'follow_up' => null];
            }
        };
    }

    /** Test-only stand-in for the five inputs that have no persisted source yet. */
    private function completeSupplementalData(): ReportSupplementalData
    {
        return new class implements ReportSupplementalData
        {
            public function reportNumber(int $assessmentCaseId, string $snapshotId): string
            {
                return 'HPP-SYNTH-0001';
            }

            public function psychologistSippNumber(int $adminId): string
            {
                return 'SIPP-SYNTH-0001';
            }

            public function recommendationRationale(string $snapshotId): string
            {
                return 'Alasan rekomendasi sintetis.';
            }

            public function aspectLabels(string $standardVersion): array
            {
                $labels = [];
                foreach (['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'] as $aspect) {
                    $labels[$aspect] = ['label_id' => "Label {$aspect}", 'label_jp' => "ラベル{$aspect}"];
                }

                return $labels;
            }

            public function dassScreeningText(string $generalCategory): array
            {
                return [
                    'narrative' => "Skrining kategori umum {$generalCategory}.",
                    'follow_up' => 'Tindak lanjut sintetis.',
                ];
            }
        };
    }
}
