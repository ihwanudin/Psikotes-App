<?php

declare(strict_types=1);

namespace Tests\Integration\Narrative;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssessmentBillingFixture;
use Tests\TestCase;

final class BilingualNarrativePersistenceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{case: int, participant: int, organization: int} */
    private function assessmentCase(): array
    {
        $fixture = AssessmentBillingFixture::create();

        return [
            'case' => (int) $fixture['case'],
            'participant' => (int) $fixture['participant'],
            'organization' => (int) $fixture['organization'],
        ];
    }

    private function validRow(int $caseId, int $version, ?string $supersedesId = null, ?string $eligibilityVersionId = null): array
    {
        $id = (string) Str::ulid();

        return [
            'id' => $id,
            'assessment_case_id' => $caseId,
            'version' => $version,
            'supersedes_id' => $supersedesId,
            'eligibility_version_id' => $eligibilityVersionId,
            'review_required' => false,
            'cluster_a_id' => 'Peserta menunjukkan kemampuan kognitif yang baik.',
            'cluster_a_jp' => '参加者は良好な認知能力を示しています。',
            'cluster_b_id' => 'Dalam aspek ketelitian, peserta berada pada tingkat memadai.',
            'cluster_b_jp' => '正確さの側面では、参加者は適切なレベルです。',
            'cluster_c_id' => null,
            'cluster_c_jp' => null,
            'cluster_d_id' => 'Minat terhadap bidang umum menunjukkan kecenderungan positif.',
            'cluster_d_jp' => '一般分野への関心は肯定的な傾向を示しています。',
            'snapshot_json' => json_encode(['type' => 'bilingual_cluster_narratives'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ];
    }

    private function eligibilityVersionId(int $caseId): string
    {
        $id = (string) Str::ulid();
        DB::table('eligibility_decision_versions')->insert([
            'id' => $id,
            'assessment_case_id' => $caseId,
            'version' => 1,
            'supersedes_id' => null,
            'standard_version' => 'GA-2026.08',
            'field_code' => 'UMUM',
            'publication_blocked' => false,
            'recommendation_label' => 'DISARANKAN',
            'iq' => 110,
            'validity' => 'V1',
            'snapshot_json' => json_encode(['type' => 'eligibility_decision_snapshot'], JSON_THROW_ON_ERROR),
            'canonical_input_json' => json_encode(['field_code' => 'UMUM'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return $id;
    }

    public function test_insert_version_1_valid_succeeds(): void
    {
        $case = $this->assessmentCase();
        $row = $this->validRow($case['case'], 1);

        DB::table('bilingual_narrative_versions')->insert($row);

        $this->assertDatabaseCount('bilingual_narrative_versions', 1);
        $this->assertDatabaseHas('bilingual_narrative_versions', [
            'id' => $row['id'],
            'assessment_case_id' => $case['case'],
            'version' => 1,
            'supersedes_id' => null,
        ]);
    }

    public function test_update_is_rejected_append_only(): void
    {
        $case = $this->assessmentCase();
        $row = $this->validRow($case['case'], 1);
        DB::table('bilingual_narrative_versions')->insert($row);

        $this->expectException(QueryException::class);

        DB::table('bilingual_narrative_versions')
            ->where('id', $row['id'])
            ->update(['cluster_a_id' => 'Updated text.']);
    }

    public function test_delete_is_rejected_append_only(): void
    {
        $case = $this->assessmentCase();
        $row = $this->validRow($case['case'], 1);
        DB::table('bilingual_narrative_versions')->insert($row);

        $this->expectException(QueryException::class);

        DB::table('bilingual_narrative_versions')
            ->where('id', $row['id'])
            ->delete();
    }

    public function test_insert_version_2_with_broken_chain_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $v1 = $this->validRow($case['case'], 1);
        DB::table('bilingual_narrative_versions')->insert($v1);

        $v2 = $this->validRow($case['case'], 2, (string) Str::ulid());

        $this->expectException(QueryException::class);

        DB::table('bilingual_narrative_versions')->insert($v2);
    }

    public function test_insert_version_2_with_correct_chain_succeeds(): void
    {
        $case = $this->assessmentCase();
        $v1 = $this->validRow($case['case'], 1);
        DB::table('bilingual_narrative_versions')->insert($v1);

        $v2 = $this->validRow($case['case'], 2, $v1['id']);
        DB::table('bilingual_narrative_versions')->insert($v2);

        $this->assertDatabaseCount('bilingual_narrative_versions', 2);
        $this->assertDatabaseHas('bilingual_narrative_versions', [
            'id' => $v2['id'],
            'version' => 2,
            'supersedes_id' => $v1['id'],
        ]);
    }

    public function test_version_1_cannot_have_supersedes_id(): void
    {
        $case = $this->assessmentCase();
        $row = $this->validRow($case['case'], 1, (string) Str::ulid());

        $this->expectException(QueryException::class);

        DB::table('bilingual_narrative_versions')->insert($row);
    }

    public function test_version_must_be_positive(): void
    {
        $case = $this->assessmentCase();
        $row = $this->validRow($case['case'], 0);

        $this->expectException(QueryException::class);

        DB::table('bilingual_narrative_versions')->insert($row);
    }

    public function test_latest_version_retrieval(): void
    {
        $case = $this->assessmentCase();
        $v1 = $this->validRow($case['case'], 1);
        $v2 = $this->validRow($case['case'], 2, $v1['id']);
        $v3 = $this->validRow($case['case'], 3, $v2['id']);

        DB::table('bilingual_narrative_versions')->insert($v1);
        DB::table('bilingual_narrative_versions')->insert($v2);
        DB::table('bilingual_narrative_versions')->insert($v3);

        $latest = DB::table('bilingual_narrative_versions')
            ->where('assessment_case_id', $case['case'])
            ->orderByDesc('version')
            ->first();

        $this->assertNotNull($latest);
        $this->assertSame($v3['id'], $latest->id);
        $this->assertSame(3, (int) $latest->version);
    }

    public function test_fk_to_eligibility_version_id_is_enforced(): void
    {
        $case = $this->assessmentCase();
        $row = $this->validRow($case['case'], 1, null, (string) Str::ulid());

        $this->expectException(QueryException::class);

        DB::table('bilingual_narrative_versions')->insert($row);
    }

    public function test_fk_to_eligibility_version_id_with_valid_reference_succeeds(): void
    {
        $case = $this->assessmentCase();
        $eligibilityId = $this->eligibilityVersionId($case['case']);
        $row = $this->validRow($case['case'], 1, null, $eligibilityId);

        DB::table('bilingual_narrative_versions')->insert($row);

        $this->assertDatabaseHas('bilingual_narrative_versions', [
            'id' => $row['id'],
            'eligibility_version_id' => $eligibilityId,
        ]);
    }

    public function test_supersedes_unique_constraint(): void
    {
        $case = $this->assessmentCase();
        $v1 = $this->validRow($case['case'], 1);
        DB::table('bilingual_narrative_versions')->insert($v1);

        $v2a = $this->validRow($case['case'], 2, $v1['id']);
        DB::table('bilingual_narrative_versions')->insert($v2a);

        $v2b = $this->validRow($case['case'], 2, $v1['id']);

        $this->expectException(QueryException::class);

        DB::table('bilingual_narrative_versions')->insert($v2b);
    }
}
