<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BranchFeeLedgerSecurityTest extends TestCase
{
    private int $ownBranch;

    private int $foreignBranch;

    private int $ownAdmin;

    private int $ownParticipant;

    private array $rows;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();

        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->ownBranch = $this->branch('F7FEEA');
            $this->foreignBranch = $this->branch('F7FEEB');
            $this->ownAdmin = $this->admin($this->ownBranch, 'f7-fee-own@example.test');
            $foreignAdmin = $this->admin($this->foreignBranch, 'f7-fee-foreign@example.test');

            $this->ownParticipant = $this->createLedger($this->ownBranch, $this->ownAdmin, 'A');
            $this->createLedger($this->foreignBranch, $foreignAdmin, 'B');

            foreach (self::tables() as [$table]) {
                $this->rows[$table] = (array) DB::table($table)->where('branch_id', $this->ownBranch)->first();
                unset($this->rows[$table]['id']);
            }
        });
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    #[DataProvider('tables')]
    public function test_rls_is_forced_and_runtime_can_only_read_tenant_rows(string $table): void
    {
        $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', [$table]);
        $this->assertTrue($security->relrowsecurity, $table);
        $this->assertTrue($security->relforcerowsecurity, $table);

        $runner = app(RlsContextRunner::class);
        foreach ([
            'service' => 2,
            'super_admin' => 2,
            'branch_admin' => 1,
            'staff' => 1,
            'psychologist' => 0,
            'participant' => 0,
        ] as $role => $expected) {
            $context = new RlsContext(
                $role,
                $this->ownBranch,
                $role === 'participant' ? $this->ownParticipant : null,
            );
            $runner->run($context, function () use ($table, $role, $expected): void {
                $this->assertSame($expected, DB::table($table)->whereIn('branch_id', [
                    $this->ownBranch,
                    $this->foreignBranch,
                ])->count(), $table.':'.$role);
            });
        }

        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true)");
        $this->assertSame(0, DB::table($table)->count());
    }

    #[DataProvider('tables')]
    public function test_user_roles_cannot_write_even_visible_rows(string $table): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('42501');

        app(RlsContextRunner::class)->run(new RlsContext('branch_admin', $this->ownBranch), function () use ($table): void {
            $this->assertSame(1, DB::table($table)->where('branch_id', $this->ownBranch)->count());
            $this->assertSame(0, DB::table($table)->where('branch_id', $this->ownBranch)->update(['updated_at' => now()]));
            DB::table($table)->insert($this->rows[$table]);
        });
    }

    public function test_service_boundary_can_write_and_constraints_enforce_fee_rule_shape(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(1, DB::table('branch_fee_rules')->where('branch_id', $this->ownBranch)->update([
                'effective_until' => now()->addMonth(),
            ]));
            DB::table('branch_fee_rules')->insert([
                'branch_id' => $this->ownBranch,
                'rate_basis' => 'base_amount',
                'rate_type' => 'fixed',
                'fixed_amount' => 0,
                'currency' => 'IDR',
                'rounding_mode' => 'floor',
                'effective_from' => now()->addMonth(),
                'created_by_admin_id' => $this->ownAdmin,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertSqlState('23514', fn () => DB::table('branch_fee_rules')->insert([
                'branch_id' => $this->ownBranch,
                'rate_basis' => 'base_amount',
                'rate_type' => 'percentage',
                'percentage_bps' => 1000,
                'fixed_amount' => 100,
                'currency' => 'IDR',
                'rounding_mode' => 'floor',
                'effective_from' => now()->addYears(2),
                'created_by_admin_id' => $this->ownAdmin,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        });
    }

    public function test_open_rule_and_withdrawal_period_uniqueness_are_database_enforced(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSqlState('23505', fn () => DB::table('branch_fee_rules')->insert([
                'branch_id' => $this->ownBranch,
                'rate_basis' => 'base_amount',
                'rate_type' => 'percentage',
                'percentage_bps' => 750,
                'currency' => 'IDR',
                'rounding_mode' => 'floor',
                'effective_from' => now()->addDay(),
                'created_by_admin_id' => $this->ownAdmin,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            $this->assertSqlState('23505', fn () => DB::table('withdrawal_requests')->insert([
                'branch_id' => $this->ownBranch,
                'period_month' => '2026-09-01',
                'public_reference' => 'WR_'.((string) Str::ulid()),
                'status' => 'submitted',
                'requested_amount' => 0,
                'currency' => 'IDR',
                'requested_by_admin_id' => $this->ownAdmin,
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        });
    }

    public function test_withdrawal_request_item_uniqueness_only_applies_to_active_history(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $entry = DB::table('commission_entries')
                ->where('branch_id', $this->ownBranch)
                ->value('id');
            $firstItem = DB::table('withdrawal_request_items')
                ->where('commission_entry_id', $entry)
                ->value('id');

            $this->assertSame(1, DB::table('withdrawal_request_items')->where('id', $firstItem)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]));

            $secondRequest = $this->withdrawalRequest(status: 'rejected');
            DB::table('withdrawal_request_items')->insert([
                'branch_id' => $this->ownBranch,
                'withdrawal_request_id' => $secondRequest,
                'commission_entry_id' => $entry,
                'amount_snapshot' => 0,
                'currency' => 'IDR',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $thirdRequest = $this->withdrawalRequest(status: 'rejected');
            $this->assertSqlState('23505', fn () => DB::table('withdrawal_request_items')->insert([
                'branch_id' => $this->ownBranch,
                'withdrawal_request_id' => $thirdRequest,
                'commission_entry_id' => $entry,
                'amount_snapshot' => 0,
                'currency' => 'IDR',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        });
    }

    public function test_runtime_privileges_do_not_grant_direct_delete_or_truncate(): void
    {
        foreach (self::tables() as [$table]) {
            $this->assertTrue(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', ?, 'SELECT') AS allowed", [$table])->allowed, $table);
            $this->assertTrue(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', ?, 'INSERT') AS allowed", [$table])->allowed, $table);
            $this->assertTrue(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', ?, 'UPDATE') AS allowed", [$table])->allowed, $table);
            $this->assertFalse(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', ?, 'DELETE') AS allowed", [$table])->allowed, $table);
            $this->assertFalse(DB::selectOne("SELECT has_table_privilege('psikotes_runtime', ?, 'TRUNCATE') AS allowed", [$table])->allowed, $table);
        }
    }

    public static function tables(): iterable
    {
        foreach ([
            'branch_fee_rules',
            'commission_entries',
            'withdrawal_requests',
            'withdrawal_request_items',
            'commission_ledger_gaps',
        ] as $table) {
            yield [$table];
        }
    }

    private function branch(string $code): int
    {
        return DB::table('branches')->insertGetId([
            'code' => $code,
            'ref_code' => $code,
            'name' => 'Branch '.$code,
            'organization_code' => $code,
            'organization_type' => 'EXTERNAL_LPK',
            'display_name' => 'Branch '.$code,
            'status' => 'ACTIVE',
            'allowed_funding_modes' => '["SPONSORED"]',
            'allowed_payer_types' => '["organization"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function admin(int $branch, string $email): int
    {
        return DB::table('admins')->insertGetId([
            'branch_id' => $branch,
            'name' => 'Branch Admin',
            'email' => $email,
            'password' => 'not-a-real-password',
            'role' => 'branch_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLedger(int $branch, int $admin, string $suffix): int
    {
        $package = DB::table('packages')->insertGetId([
            'code' => 'F7_FEE_PKG_'.$suffix,
            'name' => 'F7 Fee Package '.$suffix,
            'amount' => 1,
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
            'branch_id' => $branch,
            'referral_branch_id' => $branch,
            'referral_source' => 'link',
            'package_id' => $package,
            'source_system' => 'DIRECT_PUBLIC',
            'full_name' => 'Ledger Participant '.$suffix,
            'gender' => 'male',
            'birth_date' => '2000-01-01',
            'education_level' => 'SMA',
            'intended_field' => 'UMUM',
            'phone' => '6281111111'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderPublicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $orderPublicId,
            'participant_id' => $participant,
            'organization_id' => $branch,
            'package_id' => $package,
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => 'UMUM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'f7_fee_'.$suffix,
            'display_name' => 'F7 Fee '.$suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $orderPublicId,
            'participant_id' => $participant,
            'assessment_case_id' => $case,
            'payment_method_id' => $method,
            'status' => 'paid',
            'amount' => 1,
            'currency' => 'IDR',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rule = DB::table('branch_fee_rules')->insertGetId([
            'branch_id' => $branch,
            'rate_basis' => 'base_amount',
            'rate_type' => 'percentage',
            'percentage_bps' => 1000,
            'currency' => 'IDR',
            'rounding_mode' => 'floor',
            'effective_from' => now()->subMonth(),
            'created_by_admin_id' => $admin,
            'metadata' => json_encode(['overlapGuard' => 'service-layer'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entry = DB::table('commission_entries')->insertGetId([
            'branch_id' => $branch,
            'participant_id' => $participant,
            'source_type' => 'direct_order',
            'source_id' => $order,
            'order_id' => $order,
            'period_month' => '2026-09-01',
            'paid_at' => now(),
            'fee_rule_id' => $rule,
            'rate_basis' => 'base_amount',
            'rate_basis_amount' => 0,
            'gross_amount' => 0,
            'commission_amount' => 0,
            'currency' => 'IDR',
            'calculation_snapshot' => json_encode(['timezone' => 'Asia/Jakarta', 'rounding' => 'floor'], JSON_THROW_ON_ERROR),
            'status' => 'accrued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = DB::table('withdrawal_requests')->insertGetId([
            'branch_id' => $branch,
            'period_month' => '2026-09-01',
            'public_reference' => 'WR_'.((string) Str::ulid()),
            'status' => 'submitted',
            'requested_amount' => 0,
            'currency' => 'IDR',
            'requested_by_admin_id' => $admin,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('withdrawal_request_items')->insert([
            'branch_id' => $branch,
            'withdrawal_request_id' => $request,
            'commission_entry_id' => $entry,
            'amount_snapshot' => 0,
            'currency' => 'IDR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commission_ledger_gaps')->insert([
            'branch_id' => $branch,
            'source_type' => 'direct_order',
            'source_id' => $order,
            'reason_code' => 'fee_rule_missing',
            'paid_at' => now(),
            'currency' => 'IDR',
            'amount' => 0,
            'context' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $participant;
    }

    private function withdrawalRequest(string $status): int
    {
        return DB::table('withdrawal_requests')->insertGetId([
            'branch_id' => $this->ownBranch,
            'period_month' => '2026-09-01',
            'public_reference' => 'WR_'.((string) Str::ulid()),
            'status' => $status,
            'requested_amount' => 0,
            'currency' => 'IDR',
            'requested_by_admin_id' => $this->ownAdmin,
            'submitted_at' => now(),
            'rejected_at' => $status === 'rejected' ? now() : null,
            'rejection_reason' => $status === 'rejected' ? 'Historical rejection for active-item uniqueness proof.' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
