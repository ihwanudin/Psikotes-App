<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use App\Services\ReportRendering\ReportSupplementalData;
use App\Services\ReportRendering\SignedReportDataset;
use App\Services\Review\ReportSigningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentBillingFixture;

/**
 * PostgreSQL runtime evidence for the F6 signed-report adapter: as the
 * non-superuser runtime role under RLS it reads the signed snapshot, the
 * jsonb IST instrument payload, and ONLY dass.results.overall_category.
 */
final class SignedReportDatasetRlsTest extends TestCase
{
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    private const IST_VERSION = 'synthetic-report-ist-pg-v1';

    private string $casePublicId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->casePublicId = app(RlsContextRunner::class)->runAsService(function (): string {
            $fixture = AssessmentBillingFixture::create();
            DB::table('participants')->where('id', $fixture['participant'])->update(['test_number' => 'T26-09-PG01']);
            $this->seedIstVersion();
            $this->seedDass((int) $fixture['participant'], 'Sedang', now()->subDays(3));
            $this->seedDass((int) $fixture['participant'], 'Parah', now()->addDay());

            return (string) DB::table('assessment_cases')->where('id', $fixture['case'])->value('public_id');
        });
        $this->signCase();
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_runtime_role_is_not_privileged(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    /**
     * Evidence for a cross-lane finding: jsonb does not preserve the stored
     * text, so a checksum taken over the original JSON text can never be
     * re-verified from the payload PostgreSQL returns. The row IS readable
     * under RLS; only the byte-level checksum rule fails.
     */
    public function test_jsonb_payload_is_readable_but_not_byte_identical(): void
    {
        $row = app(RlsContextRunner::class)->runAsService(static fn (): ?object => DB::table('instrument_versions')
            ->where('code', 'ist')->where('version', self::IST_VERSION)->first(['payload', 'checksum']));

        $this->assertNotNull($row);
        $this->assertIsArray(json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR)['iq_level_bands'] ?? null);
        $this->assertFalse(hash_equals($row->checksum, hash('sha256', (string) $row->payload)));
    }

    public function test_default_adapter_reaches_every_persisted_source_under_rls(): void
    {
        $result = app(SignedReportDataset::class)->hpp($this->casePublicId);

        // Snapshot, identity, and DASS were all readable. IQ_CATEGORY stays
        // blocked on PostgreSQL until the jsonb checksum rule is decided (see
        // test above); the adapter fails closed rather than skip integrity.
        $this->assertSame([
            SignedReportDataset::TEST_DATE_UNAVAILABLE,
            SignedReportDataset::IQ_CATEGORY_UNAVAILABLE,
            SignedReportDataset::REPORT_NUMBER_UNAVAILABLE,
            SignedReportDataset::PSYCHOLOGIST_SIPP_UNAVAILABLE,
            SignedReportDataset::RECOMMENDATION_RATIONALE_UNAVAILABLE,
            SignedReportDataset::ASPECT_LABELS_UNAVAILABLE,
            SignedReportDataset::DASS_TEXT_UNAVAILABLE,
        ], $result->missing);
    }

    public function test_dass_read_selects_only_the_general_category_before_signing(): void
    {
        $supplemental = new class implements ReportSupplementalData
        {
            public ?string $captured = null;

            public function reportNumber(int $assessmentCaseId, string $snapshotId): ?string
            {
                return null;
            }

            public function psychologistSippNumber(int $adminId): ?string
            {
                return null;
            }

            public function recommendationRationale(string $snapshotId): ?string
            {
                return null;
            }

            public function aspectLabels(string $standardVersion): ?array
            {
                return null;
            }

            public function dassScreeningText(string $generalCategory): ?array
            {
                $this->captured = $generalCategory;

                return null;
            }
        };

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        (new SignedReportDataset(app(RlsContextRunner::class), $supplemental))->hpp($this->casePublicId);

        $this->assertSame('Sedang', $supplemental->captured);
        $dassQueries = array_values(array_filter($queries, static fn (string $sql): bool => preg_match('/"dass"\."|dass\./', $sql) === 1));
        $this->assertNotSame([], $dassQueries);
        foreach ($dassQueries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/depression|anxiety|stress|responses|validity_flags/i', $sql);
        }
    }

    private function signCase(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/database/seeders/data/reporting.json'), true, flags: JSON_THROW_ON_ERROR);
        $reporting = ['standard_version' => $data['standard_version'], 'base_standards' => $data['base_standards'], 'fields' => $data['fields']];
        $canonicalInput = [
            'levels' => array_fill_keys(self::ASPECTS, 4),
            'field_code' => 'UMUM',
            'iq' => 110,
            'validity' => 'V1',
            'standard_configuration' => $reporting,
            'eligibility_source_versions' => [
                'ist' => self::IST_VERSION, 'papi' => 'F0-2026.08', 'kraepelin' => 'F0-2026.08',
                'rmib' => 'F0-2026.08', 'reporting' => $reporting['standard_version'],
            ],
        ];
        $snapshot = EligibilityDecisionSnapshot::create($canonicalInput)->toArray();

        [$eligibilityId, $narrativeId, $psychologist] = app(RlsContextRunner::class)->runAsService(function () use ($canonicalInput, $snapshot): array {
            $caseId = (int) DB::table('assessment_cases')->where('public_id', $this->casePublicId)->value('id');
            $eligibilityId = (string) Str::ulid();
            DB::table('eligibility_decision_versions')->insert([
                'id' => $eligibilityId, 'assessment_case_id' => $caseId, 'version' => 1, 'supersedes_id' => null,
                'standard_version' => $snapshot['provenance']['eligibility_standard_version'],
                'field_code' => $snapshot['zone']['field_code'],
                'publication_blocked' => $snapshot['publication_blocked'],
                'recommendation_label' => $snapshot['recommendation']['label'] ?? null,
                'iq' => 110, 'validity' => 'V1',
                'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'canonical_input_json' => json_encode($canonicalInput, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            $narrativeId = (string) Str::ulid();
            DB::table('bilingual_narrative_versions')->insert([
                'id' => $narrativeId, 'assessment_case_id' => $caseId, 'version' => 1, 'supersedes_id' => null,
                'eligibility_version_id' => $eligibilityId, 'review_required' => false,
                'cluster_a_id' => 'A', 'cluster_a_jp' => 'A', 'cluster_b_id' => 'B', 'cluster_b_jp' => 'B',
                'cluster_c_id' => 'C', 'cluster_c_jp' => 'C', 'cluster_d_id' => 'D', 'cluster_d_jp' => 'D',
                'snapshot_json' => json_encode(['type' => 'bilingual_cluster_narratives'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            $adminId = DB::table('admins')->insertGetId([
                'name' => 'Psikolog Sintetis PG', 'email' => Str::uuid().'@example.test',
                'password' => bcrypt('password'), 'role' => 'psychologist',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$eligibilityId, $narrativeId, Admin::query()->findOrFail($adminId)];
        });

        $result = app(ReportSigningService::class)->sign($this->casePublicId, $psychologist, [
            'eligibility_version_id' => $eligibilityId,
            'narrative_version_id' => $narrativeId,
            'level_overrides' => [],
            'label_override' => null,
            'g7_resolutions' => [],
            'procedure_note' => null,
            'accompaniment_conditions' => null,
            'narrative_clusters' => ['A' => 'Narasi A.', 'B' => 'Narasi B.', 'C' => 'Narasi C.', 'D' => 'Narasi D.'],
        ]);
        if ($result['success'] !== true) {
            throw new \RuntimeException('Synthetic signing failed: '.$result['code'].' '.$result['message']);
        }
    }

    private function seedIstVersion(): void
    {
        $ist = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/database/seeders/data/ist.json'), true, flags: JSON_THROW_ON_ERROR);
        $payload = json_encode(['version' => self::IST_VERSION, 'iq_level_bands' => $ist['iq_level_bands']], JSON_THROW_ON_ERROR);
        DB::table('instrument_versions')->insert([
            'code' => 'ist', 'version' => self::IST_VERSION, 'source_file' => 'synthetic-report-ist-pg.json',
            'checksum' => hash('sha256', $payload), 'payload' => $payload, 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedDass(int $participantId, string $overall, \DateTimeInterface $completedAt): void
    {
        $assessment = DB::table('dass.assessments')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participantId, 'status' => 'completed',
            'started_at' => $completedAt, 'completed_at' => $completedAt,
            'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('dass.results')->insert([
            'assessment_id' => $assessment,
            'depression_raw' => 1, 'anxiety_raw' => 2, 'stress_raw' => 3,
            'depression_score' => 2, 'anxiety_score' => 4, 'stress_score' => 6,
            'depression_category' => 'PRIVATE-DEP', 'anxiety_category' => 'PRIVATE-ANX',
            'stress_category' => 'PRIVATE-STRESS', 'overall_category' => $overall,
            'follow_up' => 'monitoring',
            'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
