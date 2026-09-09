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

final class AssessmentBillingSchemaTest extends TestCase
{
    private array $charge;

    private array $bill;

    private int $otherParticipant;

    private int $otherOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        app(RlsContextRunner::class)->runAsService(function (): void {
            $organization = $this->organization('P6A');
            $participant = $this->participant($organization);
            $this->otherOrganization = $this->organization('P6A_OTHER');
            $this->otherParticipant = $this->participant($this->otherOrganization);
            $client = DB::table('integration_clients')->insertGetId(['organization_id' => $organization,
                'client_id' => 'p6a-client', 'credential_reference' => 'synthetic-only']);
            $package = DB::table('packages')->insertGetId(['code' => 'P6A', 'name' => 'Synthetic', 'amount' => 120000, 'currency' => 'IDR']);
            $attemptPublicId = (string) Str::ulid();
            $timestamp = now();
            $case = DB::table('assessment_cases')->insertGetId([
                'public_id' => $attemptPublicId, 'participant_id' => $participant,
                'organization_id' => $organization, 'package_id' => $package, 'origin' => 'INTEGRATED',
                'intended_field_snapshot' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $attempt = DB::table('assessment_participants')->insertGetId(['organization_id' => $organization,
                'integration_client_id' => $client, 'participant_id' => $participant, 'package_id' => $package,
                'assessment_case_id' => $case, 'assessment_attempt_id' => $attemptPublicId, 'source_system' => 'P6A_SOURCE',
                'external_candidate_id' => 'P6A_CANDIDATE', 'funding_mode' => 'COMMERCIAL_SELF_PAY',
                'assessment_status' => 'PROVISIONED', 'idempotency_key' => 'attempt:1',
                'request_hash' => str_repeat('a', 64), 'logical_assessment_key' => str_repeat('b', 64),
                'created_at' => $timestamp, 'updated_at' => $timestamp]);
            $method = DB::table('payment_methods')->insertGetId(['code' => 'p6a-test', 'display_name' => 'Synthetic']);
            $this->charge = ['organization_id' => $organization, 'participant_id' => $participant, 'package_id' => $package,
                'assessment_participant_id' => $attempt, 'payer_type' => 'organization', 'base_amount' => 120000,
                'consultation_requested' => true, 'consultation_amount' => 30000, 'amount' => 150000, 'currency' => 'IDR',
                'price_snapshot' => '{"packageCode":"P6A"}', 'policy_snapshot' => '{"payerType":"organization"}'];
            $this->bill = ['organization_id' => $organization, 'payer_type' => 'organization',
                'public_reference' => 'AB_'.Str::ulid(), 'amount' => 150000, 'currency' => 'IDR', 'item_count' => 1,
                'selection_hash' => str_repeat('c', 64), 'idempotency_key' => 'p6a:bill',
                'request_hash' => str_repeat('d', 64), 'payment_method_id' => $method];
        });
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    #[DataProvider('invalidRows')]
    public function test_database_rejects_invalid_money_payer_and_snapshot(string $table, array $override): void
    {
        $this->expectException(QueryException::class);
        app(RlsContextRunner::class)->runAsService(fn () => DB::table($table)->insert([
            ...($table === 'assessment_bills' ? $this->bill : $this->charge), ...$override,
        ]));
    }

    public static function invalidRows(): iterable
    {
        foreach ([['amount' => 0], ['amount' => -1], ['currency' => 'USD'], ['payer_type' => 'SPONSORED'],
            ['payer_type' => 'self'], ['item_count' => 0], ['status' => 'ready'], ['status' => 'paid'],
            ['paid_at' => '2026-08-31 00:00:00+00'], ['public_reference' => 'BAD_REFERENCE'],
            ['request_hash' => 'invalid'], ['selection_hash' => 'invalid'], ['idempotency_key' => ''],
            ['verified_at' => '2026-08-31 00:00:00+00']] as $row) {
            yield ['assessment_bills', $row];
        }
        foreach ([['amount' => 149999], ['base_amount' => -1], ['consultation_amount' => -1], ['currency' => 'USD'],
            ['payer_type' => 'WAIVED'], ['consultation_requested' => false],
            ['consultation_amount' => 0, 'amount' => 120000], ['price_snapshot' => '[]'], ['policy_snapshot' => 'null'],
            ['free_settled_at' => '2026-08-31 00:00:00+00']] as $row) {
            yield ['assessment_charges', $row];
        }
    }

    public function test_valid_zero_charge_does_not_require_bill_or_grant_access(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_charges')->insert([...$this->charge, 'base_amount' => 0, 'consultation_amount' => 0,
                'consultation_requested' => false, 'amount' => 0, 'free_settled_at' => now()]);
            $this->assertSame(0, DB::table('assessment_charges')->value('amount'));
            $this->assertSame(0, DB::table('assessment_bills')->count());
            $this->assertSame(0, DB::table('entitlements')->count());
        });
    }

    public function test_existing_foreign_participant_cannot_be_bill_payer(): void
    {
        $this->expectException(QueryException::class);
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('assessment_bills')->insert([
            ...$this->bill, 'payer_type' => 'self', 'payer_participant_id' => $this->otherParticipant,
        ]));
    }

    public function test_existing_foreign_scope_cannot_be_attached_to_charge(): void
    {
        $this->expectException(QueryException::class);
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('assessment_charges')->insert([
            ...$this->charge, 'organization_id' => $this->otherOrganization, 'participant_id' => $this->otherParticipant,
        ]));
    }

    public function test_tables_are_forced_rls_and_tenant_reads_follow_p6c_scope(): void
    {
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_bills')->insert($this->bill);
            DB::table('assessment_charges')->insert($this->charge);
        });
        // SET LOCAL survives a released savepoint inside this test's outer transaction.
        $this->assertSame('service', DB::selectOne('SELECT app_private.app_role() AS role')->role);
        foreach (['assessment_bills', 'assessment_charges'] as $table) {
            DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
            $this->assertNull(DB::selectOne('SELECT app_private.app_role() AS role')->role);
            $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', [$table]);
            $this->assertTrue($security->relrowsecurity);
            $this->assertTrue($security->relforcerowsecurity);
            $this->assertSame(0, DB::table($table)->count());
            foreach (['participant', 'branch_admin', 'super_admin'] as $role) {
                $expected = $role === 'participant' && $table === 'assessment_bills' ? 0 : 1;
                app(RlsContextRunner::class)->run(new RlsContext($role, $this->bill['organization_id'], $this->charge['participant_id']),
                    fn () => $this->assertSame($expected, DB::table($table)->count()));
            }
        }
    }

    #[DataProvider('protectedTables')]
    public function test_non_service_cannot_insert_rows(string $table): void
    {
        $this->expectException(QueryException::class);
        app(RlsContextRunner::class)->run(new RlsContext('branch_admin', $this->bill['organization_id']),
            fn () => DB::table($table)->insert($table === 'assessment_bills' ? $this->bill : $this->charge));
    }

    public static function protectedTables(): iterable
    {
        yield ['assessment_bills'];
        yield ['assessment_charges'];
    }

    public function test_organization_idempotency_is_unique_with_null_payer(): void
    {
        $this->expectException(QueryException::class);
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_bills')->insert($this->bill);
            DB::table('assessment_bills')->insert([...$this->bill, 'public_reference' => 'AB_'.Str::ulid()]);
        });
    }

    public function test_attempt_cannot_have_two_charges(): void
    {
        $this->expectException(QueryException::class);
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_charges')->insert($this->charge);
            DB::table('assessment_charges')->insert($this->charge);
        });
    }

    public function test_valid_self_and_collective_bill_rows_share_key_without_collision(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_bills')->insert([...$this->bill, 'item_count' => 10, 'amount' => 1500000]);
            DB::table('assessment_bills')->insert([...$this->bill, 'payer_type' => 'self',
                'payer_participant_id' => $this->charge['participant_id'], 'public_reference' => 'AB_'.Str::ulid(),
                'status' => 'paid', 'paid_at' => now()]);
            $this->assertSame(2, DB::table('assessment_bills')->count());
        });
    }

    private function organization(string $code): int
    {
        return DB::table('branches')->insertGetId(['code' => $code, 'ref_code' => $code, 'name' => 'Synthetic',
            'organization_code' => $code, 'display_name' => 'Synthetic']);
    }

    private function participant(int $organization): int
    {
        return DB::table('participants')->insertGetId(['branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'default', 'full_name' => 'Synthetic', 'gender' => 'male', 'birth_date' => '2000-01-01',
            'education_level' => 'SMA_SMK', 'intended_field' => 'UMUM', 'phone' => '620000000000']);
    }
}
