<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentBillingFixture;

/** PostgreSQL runtime evidence for bilingual_narrative_versions RLS, trigger guard, and SECURITY DEFINER function. */
final class BilingualNarrativeRlsTest extends TestCase
{
    private array $fixture;
    private int $caseId;
    private string $eligibilityVersionId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $result = app(RlsContextRunner::class)->runAsService(function (): array {
            $billing = AssessmentBillingFixture::create();

            $eId = (string) Str::ulid();
            DB::table('eligibility_decision_versions')->insert([
                'id' => $eId,
                'assessment_case_id' => $billing['case'],
                'version' => 1,
                'supersedes_id' => null,
                'standard_version' => 'v2.0',
                'field_code' => 'KAIGO',
                'publication_blocked' => false,
                'recommendation_label' => 'DISARANKAN',
                'iq' => 100,
                'validity' => 'V1',
                'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                'canonical_input_json' => json_encode(['iq' => 100], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return [
                'fixture' => $billing,
                'caseId' => $billing['case'],
                'eligibilityVersionId' => $eId,
            ];
        });
        $this->fixture = $result['fixture'];
        $this->caseId = $result['caseId'];
        $this->eligibilityVersionId = $result['eligibilityVersionId'];
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_runtime_role_is_psikotes_runtime_without_superuser_or_bypassrls(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    public function test_rls_is_enabled_and_forced(): void
    {
        $table = DB::table('pg_tables')
            ->where('tablename', 'bilingual_narrative_versions')
            ->first(['rowsecurity']);
        $this->assertNotNull($table);
        $this->assertTrue($table->rowsecurity);
    }

    public function test_table_privileges_are_select_and_insert_only(): void
    {
        $privileges = DB::table('information_schema.table_privileges')
            ->where('table_name', 'bilingual_narrative_versions')
            ->where('grantee', 'psikotes_runtime')
            ->pluck('privilege_type')
            ->toArray();
        sort($privileges);
        $this->assertSame(['INSERT', 'SELECT'], $privileges);
    }

    public function test_rls_policies_gate_on_service_role_only(): void
    {
        $policies = DB::table('pg_policies')
            ->where('tablename', 'bilingual_narrative_versions')
            ->orderBy('policyname')
            ->get(['policyname', 'cmd', 'permissive', 'qual', 'with_check']);

        $this->assertCount(2, $policies);

        $select = $policies->firstWhere('policyname', 'bilingual_narratives_service_select');
        $this->assertNotNull($select);
        $this->assertSame('SELECT', $select->cmd);
        $this->assertSame('PERMISSIVE', $select->permissive);
        $this->assertStringContainsString("app_private.app_role() = 'service'", $select->qual);

        $insert = $policies->firstWhere('policyname', 'bilingual_narratives_service_insert');
        $this->assertNotNull($insert);
        $this->assertSame('INSERT', $insert->cmd);
        $this->assertSame('PERMISSIVE', $insert->permissive);
        $this->assertStringContainsString("app_private.app_role() = 'service'", $insert->with_check);
    }

    public function test_guard_function_is_security_definer_with_locked_search_path(): void
    {
        $func = DB::selectOne(<<<'SQL'
            SELECT p.prosecdef, pg_get_functiondef(p.oid) AS definition
            FROM pg_proc p
            JOIN pg_namespace n ON p.pronamespace = n.oid
            WHERE n.nspname = 'app_private'
              AND p.proname = 'guard_bilingual_narrative_version'
            SQL);
        $this->assertNotNull($func);
        $this->assertTrue($func->prosecdef);
        $this->assertStringContainsString('search_path', $func->definition);
        $this->assertStringContainsString('pg_catalog', $func->definition);
        $this->assertStringContainsString('public', $func->definition);
    }

    public function test_guard_function_revoked_from_public(): void
    {
        $canExecute = DB::selectOne(<<<'SQL'
            SELECT has_function_privilege('public', p.oid, 'EXECUTE') AS can_execute
            FROM pg_proc p
            JOIN pg_namespace n ON p.pronamespace = n.oid
            WHERE n.nspname = 'app_private'
              AND p.proname = 'guard_bilingual_narrative_version'
            SQL);
        $this->assertNotNull($canExecute);
        $this->assertFalse($canExecute->can_execute);
    }

    public function test_guard_function_owned_by_migration_owner(): void
    {
        $owner = DB::selectOne(<<<'SQL'
            SELECT pg_get_userbyid(p.proowner) AS owner
            FROM pg_proc p
            JOIN pg_namespace n ON p.pronamespace = n.oid
            WHERE n.nspname = 'app_private'
              AND p.proname = 'guard_bilingual_narrative_version'
            SQL);
        $this->assertNotNull($owner);
        $this->assertSame('psikotes_owner', $owner->owner);
    }

    public function test_service_can_select_empty_table(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            $this->assertSame(0, DB::table('bilingual_narrative_versions')->count());
        });
    }

    public function test_service_can_insert_and_select_bilingual_narrative(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            $v1Id = (string) Str::ulid();
            DB::table('bilingual_narrative_versions')->insert([
                'id' => $v1Id,
                'assessment_case_id' => $this->caseId,
                'version' => 1,
                'supersedes_id' => null,
                'eligibility_version_id' => $this->eligibilityVersionId,
                'review_required' => false,
                'cluster_a_id' => 'CL-A-001',
                'cluster_a_jp' => 'クラスタAの日本語説明',
                'cluster_b_id' => null,
                'cluster_b_jp' => null,
                'cluster_c_id' => null,
                'cluster_c_jp' => null,
                'cluster_d_id' => null,
                'cluster_d_jp' => null,
                'snapshot_json' => json_encode(['clusters' => ['a']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            $this->assertSame(1, DB::table('bilingual_narrative_versions')->count());
            $row = DB::table('bilingual_narrative_versions')->first();
            $this->assertSame($v1Id, $row->id);
            $this->assertSame($this->caseId, (int) $row->assessment_case_id);
            $this->assertSame(1, (int) $row->version);
            $this->assertSame($this->eligibilityVersionId, $row->eligibility_version_id);
            $this->assertSame('CL-A-001', $row->cluster_a_id);
            $this->assertSame('クラスタAの日本語説明', $row->cluster_a_jp);
        });
    }

    public function test_non_service_roles_cannot_select(): void
    {
        // Insert as service first
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            DB::table('bilingual_narrative_versions')->insert([
                'id' => (string) Str::ulid(),
                'assessment_case_id' => $this->caseId,
                'version' => 1,
                'supersedes_id' => null,
                'eligibility_version_id' => $this->eligibilityVersionId,
                'review_required' => true,
                'cluster_a_id' => 'CL-A-002',
                'cluster_a_jp' => '別のクラスタ説明',
                'cluster_b_id' => null,
                'cluster_b_jp' => null,
                'cluster_c_id' => null,
                'cluster_c_jp' => null,
                'cluster_d_id' => null,
                'cluster_d_jp' => null,
                'snapshot_json' => json_encode(['clusters' => ['a']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        $nonServiceRoles = [
            new RlsContext('super_admin'),
            new RlsContext('psychologist'),
            new RlsContext('branch_admin', $this->fixture['organization']),
            new RlsContext('staff', $this->fixture['organization']),
            new RlsContext('participant', $this->fixture['organization'], $this->fixture['participant']),
        ];

        $runner = app(RlsContextRunner::class);
        foreach ($nonServiceRoles as $context) {
            $runner->run($context, function () use ($context): void {
                $count = DB::table('bilingual_narrative_versions')->count();
                $this->assertSame(0, $count, "RLS should block SELECT for role: {$context->role}");
            });
        }
    }

    public function test_non_service_roles_cannot_insert(): void
    {
        $nonServiceRoles = [
            new RlsContext('super_admin'),
            new RlsContext('psychologist'),
            new RlsContext('branch_admin', $this->fixture['organization']),
            new RlsContext('staff', $this->fixture['organization']),
            new RlsContext('participant', $this->fixture['organization'], $this->fixture['participant']),
        ];

        $runner = app(RlsContextRunner::class);
        foreach ($nonServiceRoles as $context) {
            $caught = false;
            try {
                $runner->run($context, function (): void {
                    DB::table('bilingual_narrative_versions')->insert([
                        'id' => (string) Str::ulid(),
                        'assessment_case_id' => $this->caseId,
                        'version' => 1,
                        'supersedes_id' => null,
                        'eligibility_version_id' => $this->eligibilityVersionId,
                        'review_required' => false,
                        'cluster_a_id' => 'CL-A-003',
                        'cluster_a_jp' => '日本語テキスト',
                        'cluster_b_id' => null,
                        'cluster_b_jp' => null,
                        'cluster_c_id' => null,
                        'cluster_c_jp' => null,
                        'cluster_d_id' => null,
                        'cluster_d_jp' => null,
                        'snapshot_json' => json_encode(['clusters' => ['a']], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                $caught = true;
                $this->assertStringContainsString('42501', $e->getMessage(), "Expected permission denied for role: {$context->role}");
            }
            $this->assertTrue($caught, "RLS should block INSERT for role: {$context->role}");
        }
    }

    public function test_update_is_rejected_by_append_only_trigger(): void
    {
        $id = (string) Str::ulid();
        $caught = false;
        try {
            app(RlsContextRunner::class)->run(new RlsContext('service'), function () use ($id): void {
                DB::table('bilingual_narrative_versions')->insert([
                    'id' => $id,
                    'assessment_case_id' => $this->caseId,
                    'version' => 1,
                    'supersedes_id' => null,
                    'eligibility_version_id' => $this->eligibilityVersionId,
                    'review_required' => false,
                    'cluster_a_id' => 'CL-A-004',
                    'cluster_a_jp' => '元の日本語説明',
                    'cluster_b_id' => null,
                    'cluster_b_jp' => null,
                    'cluster_c_id' => null,
                    'cluster_c_jp' => null,
                    'cluster_d_id' => null,
                    'cluster_d_jp' => null,
                    'snapshot_json' => json_encode(['clusters' => ['a']], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                DB::table('bilingual_narrative_versions')
                    ->where('id', $id)
                    ->update(['review_required' => true]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $caught = true;
            $msg = $e->getMessage();
            $this->assertTrue(
                str_contains($msg, '42501') || str_contains($msg, 'append-only'),
                "UPDATE should be rejected; got: {$msg}"
            );
        }
        $this->assertTrue($caught, 'UPDATE should be rejected');
    }

    public function test_delete_is_rejected_by_append_only_trigger(): void
    {
        $id = (string) Str::ulid();
        $caught = false;
        try {
            app(RlsContextRunner::class)->run(new RlsContext('service'), function () use ($id): void {
                DB::table('bilingual_narrative_versions')->insert([
                    'id' => $id,
                    'assessment_case_id' => $this->caseId,
                    'version' => 1,
                    'supersedes_id' => null,
                    'eligibility_version_id' => $this->eligibilityVersionId,
                    'review_required' => true,
                    'cluster_a_id' => 'CL-A-005',
                    'cluster_a_jp' => '削除テスト用',
                    'cluster_b_id' => null,
                    'cluster_b_jp' => null,
                    'cluster_c_id' => null,
                    'cluster_c_jp' => null,
                    'cluster_d_id' => null,
                    'cluster_d_jp' => null,
                    'snapshot_json' => json_encode(['clusters' => ['a']], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                DB::table('bilingual_narrative_versions')->where('id', $id)->delete();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $caught = true;
            $msg = $e->getMessage();
            $this->assertTrue(
                str_contains($msg, '42501') || str_contains($msg, 'append-only'),
                "DELETE should be rejected; got: {$msg}"
            );
        }
        $this->assertTrue($caught, 'DELETE should be rejected');
    }

    public function test_chain_integrity_broken_chain_is_rejected(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            $v1Id = (string) Str::ulid();
            DB::table('bilingual_narrative_versions')->insert([
                'id' => $v1Id,
                'assessment_case_id' => $this->caseId,
                'version' => 1,
                'supersedes_id' => null,
                'eligibility_version_id' => $this->eligibilityVersionId,
                'review_required' => false,
                'cluster_a_id' => 'CL-A-006',
                'cluster_a_jp' => 'チェーンテストV1',
                'cluster_b_id' => null,
                'cluster_b_jp' => null,
                'cluster_c_id' => null,
                'cluster_c_jp' => null,
                'cluster_d_id' => null,
                'cluster_d_jp' => null,
                'snapshot_json' => json_encode(['clusters' => ['a']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            // Try v2 with non-existent supersedes_id
            $caught = false;
            try {
                DB::table('bilingual_narrative_versions')->insert([
                    'id' => (string) Str::ulid(),
                    'assessment_case_id' => $this->caseId,
                    'version' => 2,
                    'supersedes_id' => (string) Str::ulid(), // non-existent
                    'eligibility_version_id' => $this->eligibilityVersionId,
                    'review_required' => true,
                    'cluster_a_id' => 'CL-A-006',
                    'cluster_a_jp' => 'チェーンテストV2',
                    'cluster_b_id' => null,
                    'cluster_b_jp' => null,
                    'cluster_c_id' => null,
                    'cluster_c_jp' => null,
                    'cluster_d_id' => null,
                    'cluster_d_jp' => null,
                    'snapshot_json' => json_encode(['clusters' => ['a']], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                $caught = true;
                $this->assertStringContainsString('chain invalid', $e->getMessage());
            }
            $this->assertTrue($caught, 'Broken chain should be rejected');
        });
    }

    public function test_valid_version_chain_is_accepted(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            $v1Id = (string) Str::ulid();
            DB::table('bilingual_narrative_versions')->insert([
                'id' => $v1Id,
                'assessment_case_id' => $this->caseId,
                'version' => 1,
                'supersedes_id' => null,
                'eligibility_version_id' => $this->eligibilityVersionId,
                'review_required' => false,
                'cluster_a_id' => 'CL-A-007',
                'cluster_a_jp' => '正常チェーンV1',
                'cluster_b_id' => null,
                'cluster_b_jp' => null,
                'cluster_c_id' => null,
                'cluster_c_jp' => null,
                'cluster_d_id' => null,
                'cluster_d_jp' => null,
                'snapshot_json' => json_encode(['clusters' => ['a']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            $v2Id = (string) Str::ulid();
            DB::table('bilingual_narrative_versions')->insert([
                'id' => $v2Id,
                'assessment_case_id' => $this->caseId,
                'version' => 2,
                'supersedes_id' => $v1Id,
                'eligibility_version_id' => $this->eligibilityVersionId,
                'review_required' => true,
                'cluster_a_id' => 'CL-A-007',
                'cluster_a_jp' => '正常チェーンV2',
                'cluster_b_id' => null,
                'cluster_b_jp' => null,
                'cluster_c_id' => null,
                'cluster_c_jp' => null,
                'cluster_d_id' => null,
                'cluster_d_jp' => null,
                'snapshot_json' => json_encode(['clusters' => ['a', 'b']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            $this->assertSame(2, DB::table('bilingual_narrative_versions')->count());
            $v2 = DB::table('bilingual_narrative_versions')->where('id', $v2Id)->first();
            $this->assertSame($v1Id, $v2->supersedes_id);
            $this->assertSame(2, (int) $v2->version);
        });
    }

    public function test_invalid_snapshot_json_type_is_rejected_by_check_constraint(): void
    {
        $runner = app(RlsContextRunner::class);
        $service = new RlsContext('service');

        // Array (valid JSON, not an object) - should fail CHECK constraint
        $caught = false;
        try {
            $runner->run($service, function (): void {
                DB::table('bilingual_narrative_versions')->insert([
                    'id' => (string) Str::ulid(),
                    'assessment_case_id' => $this->caseId,
                    'version' => 1,
                    'supersedes_id' => null,
                    'eligibility_version_id' => $this->eligibilityVersionId,
                    'review_required' => false,
                    'cluster_a_id' => null,
                    'cluster_a_jp' => null,
                    'cluster_b_id' => null,
                    'cluster_b_jp' => null,
                    'cluster_c_id' => null,
                    'cluster_c_jp' => null,
                    'cluster_d_id' => null,
                    'cluster_d_jp' => null,
                    'snapshot_json' => json_encode([1, 2, 3], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $caught = true;
            $this->assertStringContainsString('23514', $e->getMessage());
        }
        $this->assertTrue($caught, 'Array snapshot_json should be rejected by CHECK constraint');

        // Raw string (not valid JSON at all) - fails at type level
        $caught = false;
        try {
            $runner->run($service, function (): void {
                DB::table('bilingual_narrative_versions')->insert([
                    'id' => (string) Str::ulid(),
                    'assessment_case_id' => $this->caseId,
                    'version' => 1,
                    'supersedes_id' => null,
                    'eligibility_version_id' => $this->eligibilityVersionId,
                    'review_required' => false,
                    'cluster_a_id' => null,
                    'cluster_a_jp' => null,
                    'cluster_b_id' => null,
                    'cluster_b_jp' => null,
                    'cluster_c_id' => null,
                    'cluster_c_jp' => null,
                    'cluster_d_id' => null,
                    'cluster_d_jp' => null,
                    'snapshot_json' => 'not_json_at_all',
                    'created_at' => now(),
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $caught = true;
            $msg = $e->getMessage();
            $this->assertTrue(
                str_contains($msg, '23514') || str_contains($msg, '22P02'),
                "Non-JSON snapshot should be rejected; got: {$msg}"
            );
        }
        $this->assertTrue($caught, 'Non-JSON snapshot should be rejected');
    }

    public function test_initial_version_with_supersedes_id_is_rejected(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            $caught = false;
            try {
                DB::table('bilingual_narrative_versions')->insert([
                    'id' => (string) Str::ulid(),
                    'assessment_case_id' => $this->caseId,
                    'version' => 1,
                    'supersedes_id' => (string) Str::ulid(),
                    'eligibility_version_id' => $this->eligibilityVersionId,
                    'review_required' => false,
                    'cluster_a_id' => null,
                    'cluster_a_jp' => null,
                    'cluster_b_id' => null,
                    'cluster_b_jp' => null,
                    'cluster_c_id' => null,
                    'cluster_c_jp' => null,
                    'cluster_d_id' => null,
                    'cluster_d_jp' => null,
                    'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                $caught = true;
                $this->assertStringContainsString('initial version invalid', $e->getMessage());
            }
            $this->assertTrue($caught, 'v1 with supersedes_id should be rejected');
        });
    }

    public function test_empty_context_cannot_access_table(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            DB::table('bilingual_narrative_versions')->insert([
                'id' => (string) Str::ulid(),
                'assessment_case_id' => $this->caseId,
                'version' => 1,
                'supersedes_id' => null,
                'eligibility_version_id' => $this->eligibilityVersionId,
                'review_required' => false,
                'cluster_a_id' => null,
                'cluster_a_jp' => null,
                'cluster_b_id' => null,
                'cluster_b_jp' => null,
                'cluster_c_id' => null,
                'cluster_c_jp' => null,
                'cluster_d_id' => null,
                'cluster_d_jp' => null,
                'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        // Clear context
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");

        $count = DB::table('bilingual_narrative_versions')->count();
        $this->assertSame(0, $count, 'Empty context should not see any rows');
    }
}
