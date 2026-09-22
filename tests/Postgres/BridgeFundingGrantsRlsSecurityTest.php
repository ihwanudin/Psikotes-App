<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

final class BridgeFundingGrantsRlsSecurityTest extends TestCase
{
    private int $branchId;

    private int $adminId;

    private int $orderId;

    private int $participantId;

    /** @var array<string, mixed> */
    private array $row;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();

        app(RlsContextRunner::class)->runAsService(function (): void {
            $suffix = 'BFG_'.Str::random(8);
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
            DB::table('package_items')->insert([
                ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ]);
            $participant = DB::table('participants')->insertGetId([
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
            $this->participantId = $participant;
            // Unique per-run code, not the shared 'manual_transfer' constant
            // -- this whole setUp runs inside a rolled-back transaction, but
            // a unique code avoids even a transient collision with whatever
            // other tests running concurrently in the same database expect
            // of that shared row.
            $method = DB::table('payment_methods')->insertGetId([
                'code' => 'bfg-'.$suffix,
                'display_name' => 'Transfer Manual',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $orderPublicId = (string) Str::ulid();
            $case = DB::table('assessment_cases')->insertGetId([
                'public_id' => $orderPublicId,
                'participant_id' => $participant,
                'organization_id' => $this->branchId,
                'package_id' => $package,
                'origin' => 'DIRECT_PUBLIC',
                'intended_field_snapshot' => 'KAIGO',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->orderId = DB::table('orders')->insertGetId([
                'public_id' => $orderPublicId,
                'participant_id' => $participant,
                'assessment_case_id' => $case,
                'payment_method_id' => $method,
                'status' => 'bridge_funded',
                'amount' => 250_000,
                'currency' => 'IDR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->row = [
                'order_id' => $this->orderId,
                'participant_id' => $participant,
                'branch_id' => $this->branchId,
                'amount' => 250_000,
                'currency' => 'IDR',
                'management_reference' => 'Surat instruksi holding No. 001/HC/2026',
                'approved_by_admin_id' => $this->adminId,
                'approved_at' => now(),
                'status' => 'invoiced',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            DB::table('bridge_funding_grants')->insert($this->row);
        });
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_rls_is_forced(): void
    {
        $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', ['bridge_funding_grants']);
        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
    }

    public function test_only_service_and_super_admin_can_read(): void
    {
        $runner = app(RlsContextRunner::class);
        foreach ([
            'service' => 1,
            'super_admin' => 1,
            'branch_admin' => 0,
            'staff' => 0,
            'psychologist' => 0,
            'participant' => 0,
        ] as $role => $expected) {
            $context = new RlsContext($role, $this->branchId, $role === 'participant' ? $this->participantId : null);
            $runner->run($context, function () use ($expected, $role): void {
                $this->assertSame($expected, DB::table('bridge_funding_grants')->where('order_id', $this->orderId)->count(), $role);
            });
        }
    }

    public function test_super_admin_cannot_write_even_visible_rows(): void
    {
        // RLS row-visibility filtering, not a GRANT-level denial: the write
        // policy's USING clause evaluates false for super_admin, so the row
        // simply isn't visible to UPDATE -- 0 rows affected, no exception
        // (same pattern as BranchFeeLedgerSecurityTest's equivalent check).
        app(RlsContextRunner::class)->run(new RlsContext('super_admin', $this->branchId), function (): void {
            $this->assertSame(1, DB::table('bridge_funding_grants')->where('order_id', $this->orderId)->count());
            $this->assertSame(0, DB::table('bridge_funding_grants')->where('order_id', $this->orderId)->update(['status' => 'written_off']));
        });
    }

    public function test_direct_insert_without_service_context_is_denied(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('42501');

        app(RlsContextRunner::class)->run(new RlsContext('super_admin', $this->branchId), function (): void {
            DB::table('bridge_funding_grants')->insert([...$this->row, 'order_id' => $this->orderId]);
        });
    }

    public function test_service_context_can_write(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(1, DB::table('bridge_funding_grants')->where('order_id', $this->orderId)->update([
                'status' => 'written_off',
                'updated_at' => now(),
            ]));
        });
    }

    public function test_runtime_privileges_do_not_grant_direct_delete_or_truncate(): void
    {
        $this->assertTrue(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', 'bridge_funding_grants', 'SELECT') AS allowed")->allowed);
        $this->assertTrue(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', 'bridge_funding_grants', 'INSERT') AS allowed")->allowed);
        $this->assertTrue(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', 'bridge_funding_grants', 'UPDATE') AS allowed")->allowed);
        $this->assertFalse(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', 'bridge_funding_grants', 'DELETE') AS allowed")->allowed);
        $this->assertFalse(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', 'bridge_funding_grants', 'TRUNCATE') AS allowed")->allowed);
    }

    public function test_active_order_uniqueness_is_database_enforced(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSqlState('23505', fn () => DB::table('bridge_funding_grants')->insert([
                ...$this->row,
                'status' => 'invoiced',
                'management_reference' => 'A second, concurrent-looking grant attempt for the same order.',
            ]));

            // A written_off grant does not block a fresh active grant for
            // the same order (only invoiced/collected are "active").
            $this->assertSame(1, DB::table('bridge_funding_grants')->where('order_id', $this->orderId)->update(['status' => 'written_off']));
            DB::table('bridge_funding_grants')->insert([...$this->row, 'status' => 'invoiced']);
            $this->assertSame(2, DB::table('bridge_funding_grants')->where('order_id', $this->orderId)->count());
        });
    }

    public function test_money_reference_and_status_checks_are_database_enforced(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSqlState('23514', fn () => DB::table('bridge_funding_grants')->insert([
                ...$this->row,
                'order_id' => $this->orderId,
                'amount' => 0,
            ]));
            $this->assertSqlState('23514', fn () => DB::table('bridge_funding_grants')->insert([
                ...$this->row,
                'management_reference' => '   ',
            ]));
            $this->assertSqlState('23514', fn () => DB::table('bridge_funding_grants')->insert([
                ...$this->row,
                'status' => 'collected',
                'collected_at' => null,
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
