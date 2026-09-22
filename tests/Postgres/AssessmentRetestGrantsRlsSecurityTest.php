<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

final class AssessmentRetestGrantsRlsSecurityTest extends TestCase
{
    private int $branchId;

    private int $adminId;

    private int $participantId;

    private int $grantId;

    /** @var array<string, mixed> */
    private array $row;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();

        app(RlsContextRunner::class)->runAsService(function (): void {
            $suffix = 'ARG_'.Str::random(8);
            $this->branchId = DB::table('branches')->insertGetId([
                'code' => $suffix,
                'ref_code' => $suffix,
                'name' => 'Branch '.$suffix,
                'organization_code' => $suffix,
                'organization_type' => 'EXTERNAL_LPK',
                'display_name' => 'Branch '.$suffix,
                'status' => 'ACTIVE',
                'allowed_funding_modes' => '["SPONSORED"]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->adminId = DB::table('admins')->insertGetId([
                'branch_id' => null,
                'name' => 'Super Admin',
                'email' => Str::lower($suffix).'@example.test',
                'password' => 'not-a-real-password',
                'role' => 'super_admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $package = DB::table('packages')->insertGetId([
                'code' => $suffix,
                'name' => 'Package '.$suffix,
                'amount' => 250_000,
                'currency' => 'IDR',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->participantId = DB::table('participants')->insertGetId([
                'branch_id' => $this->branchId,
                'referral_branch_id' => $this->branchId,
                'referral_source' => 'default',
                'package_id' => $package,
                'source_system' => 'DIRECT_PUBLIC',
                'full_name' => 'Participant '.$suffix,
                'gender' => 'female',
                'birth_date' => '2001-04-15',
                'education_level' => 'SMA/SMK',
                'intended_field' => 'KAIGO',
                'phone' => '+6281234567890',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->row = [
                'public_id' => (string) Str::ulid(),
                'participant_id' => $this->participantId,
                'test_type' => 'ist',
                'assessment_case_id' => null,
                'attempt_number' => 4,
                'authorization_id' => 'grant:v1:retest:'.$suffix,
                'reason' => 'Insiden identitas ditinjau oleh admin berwenang.',
                'approved_by_admin_id' => $this->adminId,
                'approved_at' => now(),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $this->grantId = DB::table('assessment_retest_grants')->insertGetId($this->row);
        });
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_rls_is_forced(): void
    {
        $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', ['assessment_retest_grants']);
        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
    }

    public function test_only_service_super_admin_and_psychologist_can_read(): void
    {
        $runner = app(RlsContextRunner::class);
        foreach ([
            'service' => 1,
            'super_admin' => 1,
            'psychologist' => 1,
            'branch_admin' => 0,
            'staff' => 0,
            'participant' => 0,
        ] as $role => $expected) {
            $context = new RlsContext($role, $this->branchId, $role === 'participant' ? $this->participantId : null);
            $runner->run($context, function () use ($expected, $role): void {
                $this->assertSame($expected, DB::table('assessment_retest_grants')->where('id', $this->grantId)->count(), $role);
            });
        }
    }

    public function test_super_admin_cannot_write_even_visible_rows(): void
    {
        // RLS row-visibility filtering, not a GRANT-level denial: the write
        // policy's USING clause evaluates false for super_admin, so the row
        // simply isn't visible to UPDATE -- 0 rows affected, no exception
        // (same pattern established for bridge_funding_grants).
        app(RlsContextRunner::class)->run(new RlsContext('super_admin', $this->branchId), function (): void {
            $this->assertSame(1, DB::table('assessment_retest_grants')->where('id', $this->grantId)->count());
            $this->assertSame(0, DB::table('assessment_retest_grants')->where('id', $this->grantId)->update(['status' => 'revoked']));
        });
    }

    public function test_direct_insert_without_service_context_is_denied(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('42501');

        app(RlsContextRunner::class)->run(new RlsContext('super_admin', $this->branchId), function (): void {
            DB::table('assessment_retest_grants')->insert([
                ...$this->row,
                'public_id' => (string) Str::ulid(),
                'attempt_number' => 5,
            ]);
        });
    }

    public function test_service_context_can_write(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(1, DB::table('assessment_retest_grants')->where('id', $this->grantId)->update([
                'status' => 'consumed',
                'consumed_at' => now(),
                'updated_at' => now(),
            ]));
        });
    }

    public function test_runtime_privileges_do_not_grant_direct_delete_or_truncate(): void
    {
        $this->assertTrue(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', 'assessment_retest_grants', 'SELECT') AS allowed")->allowed);
        $this->assertTrue(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', 'assessment_retest_grants', 'INSERT') AS allowed")->allowed);
        $this->assertTrue(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', 'assessment_retest_grants', 'UPDATE') AS allowed")->allowed);
        $this->assertFalse(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', 'assessment_retest_grants', 'DELETE') AS allowed")->allowed);
        $this->assertFalse(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', 'assessment_retest_grants', 'TRUNCATE') AS allowed")->allowed);
    }

    public function test_active_attempt_uniqueness_is_database_enforced(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSqlState('23505', fn () => DB::table('assessment_retest_grants')->insert([
                ...$this->row,
                'public_id' => (string) Str::ulid(),
            ]));

            // A revoked grant does not block a fresh active grant for the
            // same attempt slot (only 'active' status is in the partial
            // unique index).
            $this->assertSame(1, DB::table('assessment_retest_grants')->where('id', $this->grantId)->update(['status' => 'revoked']));
            DB::table('assessment_retest_grants')->insert([...$this->row, 'public_id' => (string) Str::ulid()]);
            $this->assertSame(2, DB::table('assessment_retest_grants')->where('participant_id', $this->participantId)->where('attempt_number', 4)->count());
        });
    }

    public function test_reason_authorization_status_and_test_type_checks_are_database_enforced(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSqlState('23514', fn () => DB::table('assessment_retest_grants')->insert([
                ...$this->row,
                'public_id' => (string) Str::ulid(),
                'attempt_number' => 5,
                'reason' => '   ',
            ]));
            $this->assertSqlState('23514', fn () => DB::table('assessment_retest_grants')->insert([
                ...$this->row,
                'public_id' => (string) Str::ulid(),
                'attempt_number' => 5,
                'authorization_id' => '  ',
            ]));
            $this->assertSqlState('23514', fn () => DB::table('assessment_retest_grants')->insert([
                ...$this->row,
                'public_id' => (string) Str::ulid(),
                'attempt_number' => 5,
                'test_type' => 'not-a-real-instrument',
            ]));
            $this->assertSqlState('23514', fn () => DB::table('assessment_retest_grants')->insert([
                ...$this->row,
                'public_id' => (string) Str::ulid(),
                'attempt_number' => 5,
                'status' => 'consumed',
                'consumed_at' => null,
            ]));
        });
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail("Expected SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0] ?? null, $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
