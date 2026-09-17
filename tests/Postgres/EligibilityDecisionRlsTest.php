<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentBillingFixture;

/** PostgreSQL runtime evidence for eligibility_decision_versions RLS, trigger guard, and SECURITY DEFINER function. */
final class EligibilityDecisionRlsTest extends TestCase
{
    private array $fixture;

    private int $caseId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $result = app(RlsContextRunner::class)->runAsService(function (): array {
            $billing = AssessmentBillingFixture::create();

            return ['fixture' => $billing, 'caseId' => $billing['case']];
        });
        $this->fixture = $result['fixture'];
        $this->caseId = $result['caseId'];
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
            ->where('tablename', 'eligibility_decision_versions')
            ->first(['rowsecurity']);
        $this->assertNotNull($table);
        $this->assertTrue($table->rowsecurity);
    }

    public function test_table_privileges_are_select_and_insert_only(): void
    {
        $privileges = DB::table('information_schema.table_privileges')
            ->where('table_name', 'eligibility_decision_versions')
            ->where('grantee', 'psikotes_runtime')
            ->pluck('privilege_type')
            ->toArray();
        sort($privileges);
        $this->assertSame(['INSERT', 'SELECT'], $privileges);
    }

    public function test_rls_policies_gate_on_service_role_only(): void
    {
        $policies = DB::table('pg_policies')
            ->where('tablename', 'eligibility_decision_versions')
            ->orderBy('policyname')
            ->get(['policyname', 'cmd', 'permissive', 'qual', 'with_check']);

        $this->assertCount(2, $policies);

        $select = $policies->firstWhere('policyname', 'eligibility_decisions_service_select');
        $this->assertNotNull($select);
        $this->assertSame('SELECT', $select->cmd);
        $this->assertSame('PERMISSIVE', $select->permissive);
        $this->assertStringContainsString("app_private.app_role() = 'service'", $select->qual);

        $insert = $policies->firstWhere('policyname', 'eligibility_decisions_service_insert');
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
              AND p.proname = 'guard_eligibility_decision_version'
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
              AND p.proname = 'guard_eligibility_decision_version'
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
              AND p.proname = 'guard_eligibility_decision_version'
            SQL);
        $this->assertNotNull($owner);
        $this->assertSame('psikotes_owner', $owner->owner);
    }

    public function test_service_can_select_empty_table(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            $this->assertSame(0, DB::table('eligibility_decision_versions')->count());
        });
    }

    public function test_service_can_insert_and_select_eligibility_decision(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            $v1Id = (string) Str::ulid();
            DB::table('eligibility_decision_versions')->insert([
                'id' => $v1Id,
                'assessment_case_id' => $this->caseId,
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

            $this->assertSame(1, DB::table('eligibility_decision_versions')->count());
            $row = DB::table('eligibility_decision_versions')->first();
            $this->assertSame($v1Id, $row->id);
            $this->assertSame($this->caseId, (int) $row->assessment_case_id);
            $this->assertSame(1, (int) $row->version);
            $this->assertSame('KAIGO', $row->field_code);
            $this->assertSame(100, (int) $row->iq);
            $this->assertSame('V1', $row->validity);
        });
    }

    public function test_non_service_roles_cannot_select(): void
    {
        // Insert as service first
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            DB::table('eligibility_decision_versions')->insert([
                'id' => (string) Str::ulid(),
                'assessment_case_id' => $this->caseId,
                'version' => 1,
                'supersedes_id' => null,
                'standard_version' => 'v2.0',
                'field_code' => 'UMUM',
                'publication_blocked' => false,
                'iq' => 90,
                'validity' => 'V2',
                'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                'canonical_input_json' => json_encode(['iq' => 90], JSON_THROW_ON_ERROR),
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
                $count = DB::table('eligibility_decision_versions')->count();
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
                    DB::table('eligibility_decision_versions')->insert([
                        'id' => (string) Str::ulid(),
                        'assessment_case_id' => $this->caseId,
                        'version' => 1,
                        'supersedes_id' => null,
                        'standard_version' => 'v2.0',
                        'field_code' => 'UMUM',
                        'publication_blocked' => false,
                        'iq' => 90,
                        'validity' => 'V2',
                        'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                        'canonical_input_json' => json_encode(['iq' => 90], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                });
            } catch (QueryException $e) {
                $caught = true;
                $this->assertStringContainsString('42501', $e->getMessage(), "Expected permission denied for role: {$context->role}");
            }
            $this->assertTrue($caught, "RLS should block INSERT for role: {$context->role}");
        }
    }

    public function test_update_is_rejected_by_append_only_trigger(): void
    {
        $caught = false;
        try {
            app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
                $id = (string) Str::ulid();
                DB::table('eligibility_decision_versions')->insert([
                    'id' => $id,
                    'assessment_case_id' => $this->caseId,
                    'version' => 1,
                    'supersedes_id' => null,
                    'standard_version' => 'v2.0',
                    'field_code' => 'KENSETSU',
                    'publication_blocked' => false,
                    'iq' => 105,
                    'validity' => 'V1',
                    'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                    'canonical_input_json' => json_encode(['iq' => 105], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                DB::table('eligibility_decision_versions')
                    ->where('id', $id)
                    ->update(['iq' => 110]);
            });
        } catch (QueryException $e) {
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
                DB::table('eligibility_decision_versions')->insert([
                    'id' => $id,
                    'assessment_case_id' => $this->caseId,
                    'version' => 1,
                    'supersedes_id' => null,
                    'standard_version' => 'v2.0',
                    'field_code' => 'SEIZOU',
                    'publication_blocked' => false,
                    'iq' => 95,
                    'validity' => 'V3',
                    'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                    'canonical_input_json' => json_encode(['iq' => 95], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                DB::table('eligibility_decision_versions')->where('id', $id)->delete();
            });
        } catch (QueryException $e) {
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
            DB::table('eligibility_decision_versions')->insert([
                'id' => $v1Id,
                'assessment_case_id' => $this->caseId,
                'version' => 1,
                'supersedes_id' => null,
                'standard_version' => 'v2.0',
                'field_code' => 'GAISHOKU',
                'publication_blocked' => false,
                'iq' => 110,
                'validity' => 'V2',
                'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                'canonical_input_json' => json_encode(['iq' => 110], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            // Try v2 with non-existent supersedes_id
            $caught = false;
            try {
                DB::table('eligibility_decision_versions')->insert([
                    'id' => (string) Str::ulid(),
                    'assessment_case_id' => $this->caseId,
                    'version' => 2,
                    'supersedes_id' => (string) Str::ulid(), // non-existent
                    'standard_version' => 'v2.1',
                    'field_code' => 'GAISHOKU',
                    'publication_blocked' => false,
                    'iq' => 112,
                    'validity' => 'V2',
                    'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                    'canonical_input_json' => json_encode(['iq' => 112], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            } catch (QueryException $e) {
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
            DB::table('eligibility_decision_versions')->insert([
                'id' => $v1Id,
                'assessment_case_id' => $this->caseId,
                'version' => 1,
                'supersedes_id' => null,
                'standard_version' => 'v2.0',
                'field_code' => 'NOUGYOU',
                'publication_blocked' => false,
                'iq' => 100,
                'validity' => 'V1',
                'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                'canonical_input_json' => json_encode(['iq' => 100], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            $v2Id = (string) Str::ulid();
            DB::table('eligibility_decision_versions')->insert([
                'id' => $v2Id,
                'assessment_case_id' => $this->caseId,
                'version' => 2,
                'supersedes_id' => $v1Id,
                'standard_version' => 'v2.1',
                'field_code' => 'NOUGYOU',
                'publication_blocked' => false,
                'iq' => 105,
                'validity' => 'V1',
                'snapshot_json' => json_encode(['result' => 'updated'], JSON_THROW_ON_ERROR),
                'canonical_input_json' => json_encode(['iq' => 105], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            $this->assertSame(2, DB::table('eligibility_decision_versions')->count());
            $v2 = DB::table('eligibility_decision_versions')->where('id', $v2Id)->first();
            $this->assertSame($v1Id, $v2->supersedes_id);
            $this->assertSame(2, (int) $v2->version);
        });
    }

    public function test_invalid_field_code_is_rejected_by_check_constraint(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            $caught = false;
            try {
                DB::table('eligibility_decision_versions')->insert([
                    'id' => (string) Str::ulid(),
                    'assessment_case_id' => $this->caseId,
                    'version' => 1,
                    'supersedes_id' => null,
                    'standard_version' => 'v2.0',
                    'field_code' => 'INVALID_FIELD',
                    'publication_blocked' => false,
                    'iq' => 100,
                    'validity' => 'V1',
                    'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                    'canonical_input_json' => json_encode(['iq' => 100], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            } catch (QueryException $e) {
                $caught = true;
                $this->assertStringContainsString('23514', $e->getMessage());
            }
            $this->assertTrue($caught, 'Invalid field_code should be rejected by CHECK constraint');
        });
    }

    public function test_invalid_iq_range_is_rejected_by_check_constraint(): void
    {
        $runner = app(RlsContextRunner::class);
        $service = new RlsContext('service');

        foreach ([0, 301] as $invalidIq) {
            $caught = false;
            try {
                $runner->run($service, function () use ($invalidIq): void {
                    DB::table('eligibility_decision_versions')->insert([
                        'id' => (string) Str::ulid(),
                        'assessment_case_id' => $this->caseId,
                        'version' => 1,
                        'supersedes_id' => null,
                        'standard_version' => 'v2.0',
                        'field_code' => 'UMUM',
                        'publication_blocked' => false,
                        'iq' => $invalidIq,
                        'validity' => 'V1',
                        'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                        'canonical_input_json' => json_encode(['iq' => $invalidIq], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                });
            } catch (QueryException $e) {
                $caught = true;
                $this->assertStringContainsString('23514', $e->getMessage());
            }
            $this->assertTrue($caught, "IQ {$invalidIq} should be rejected by CHECK constraint");
        }
    }

    public function test_invalid_validity_is_rejected_by_check_constraint(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            $caught = false;
            try {
                DB::table('eligibility_decision_versions')->insert([
                    'id' => (string) Str::ulid(),
                    'assessment_case_id' => $this->caseId,
                    'version' => 1,
                    'supersedes_id' => null,
                    'standard_version' => 'v2.0',
                    'field_code' => 'UMUM',
                    'publication_blocked' => false,
                    'iq' => 100,
                    'validity' => 'INVALID',
                    'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                    'canonical_input_json' => json_encode(['iq' => 100], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            } catch (QueryException $e) {
                $caught = true;
                $msg = $e->getMessage();
                $this->assertTrue(
                    str_contains($msg, '23514') || str_contains($msg, '22001'),
                    "Invalid validity should be rejected; got: {$msg}"
                );
            }
            $this->assertTrue($caught, 'Invalid validity should be rejected');
        });
    }

    public function test_initial_version_with_supersedes_id_is_rejected(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            $caught = false;
            try {
                DB::table('eligibility_decision_versions')->insert([
                    'id' => (string) Str::ulid(),
                    'assessment_case_id' => $this->caseId,
                    'version' => 1,
                    'supersedes_id' => (string) Str::ulid(),
                    'standard_version' => 'v2.0',
                    'field_code' => 'UMUM',
                    'publication_blocked' => false,
                    'iq' => 100,
                    'validity' => 'V1',
                    'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                    'canonical_input_json' => json_encode(['iq' => 100], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            } catch (QueryException $e) {
                $caught = true;
                $this->assertStringContainsString('initial version invalid', $e->getMessage());
            }
            $this->assertTrue($caught, 'v1 with supersedes_id should be rejected');
        });
    }

    public function test_empty_context_cannot_access_table(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
            DB::table('eligibility_decision_versions')->insert([
                'id' => (string) Str::ulid(),
                'assessment_case_id' => $this->caseId,
                'version' => 1,
                'supersedes_id' => null,
                'standard_version' => 'v2.0',
                'field_code' => 'UMUM',
                'publication_blocked' => false,
                'iq' => 100,
                'validity' => 'V1',
                'snapshot_json' => json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR),
                'canonical_input_json' => json_encode(['iq' => 100], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        // Clear context
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");

        $count = DB::table('eligibility_decision_versions')->count();
        $this->assertSame(0, $count, 'Empty context should not see any rows');
    }
}
