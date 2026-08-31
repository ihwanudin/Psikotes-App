<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentBillingFixture as Fixture;

final class AssessmentBillItemsSchemaTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(Schema::hasTable('assessment_bill_items'));
        $this->assertTrue(Schema::hasTable('assessment_entitlements'));
        DB::beginTransaction();
        $this->fixture = app(RlsContextRunner::class)->runAsService(fn () => Fixture::create());
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    #[DataProvider('invalidEntitlements')]
    public function test_invalid_entitlement_state_is_rejected(array $override): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        app(RlsContextRunner::class)->runAsService(fn () => DB::table('assessment_entitlements')
            ->insert([...Fixture::entitlement($this->fixture), ...$override]));
    }

    public static function invalidEntitlements(): iterable
    {
        foreach ([['test_type' => 'other'], ['status' => 'paid'], ['status' => 'ready'],
            ['ready_at' => '2026-08-31 01:00:00+00'], ['status' => 'in_progress', 'ready_at' => '2026-08-31 01:00:00+00'],
            ['status' => 'done', 'ready_at' => '2026-08-31 01:00:00+00', 'started_at' => '2026-08-31 02:00:00+00'],
            ['status' => 'in_progress', 'ready_at' => '2026-08-31 02:00:00+00', 'started_at' => '2026-08-31 01:00:00+00'],
            ['status' => 'done', 'ready_at' => '2026-08-31 01:00:00+00', 'started_at' => '2026-08-31 03:00:00+00', 'completed_at' => '2026-08-31 02:00:00+00']] as $row) {
            yield [$row];
        }
    }

    #[DataProvider('invalidPayers')]
    public function test_item_payer_null_cannot_bypass_self_binding(string $payer, string $mutation): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        app(RlsContextRunner::class)->runAsService(function () use ($payer, $mutation): void {
            $fixture = Fixture::create($payer);
            $row = Fixture::item($fixture);
            $row['payer_participant_id'] = $mutation === 'null' ? null : $fixture['participant'];
            DB::table('assessment_bill_items')->insert($row);
        });
    }

    public static function invalidPayers(): iterable
    {
        yield ['self', 'null'];
        yield ['organization', 'participant'];
    }

    public function test_zero_charge_cannot_enter_paid_bill(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_charges')->where('id', $this->fixture['charge'])->update(['base_amount' => 0, 'amount' => 0]);
            DB::table('assessment_bill_items')->insert([...Fixture::item($this->fixture), 'amount' => 0]);
        });
    }

    #[DataProvider('foreignFields')]
    public function test_existing_foreign_ids_cannot_be_linked(string $table, string $field, string $key): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        app(RlsContextRunner::class)->runAsService(function () use ($table, $field, $key): void {
            $other = Fixture::create();
            $row = $table === 'assessment_bill_items' ? Fixture::item($this->fixture) : Fixture::entitlement($this->fixture);
            DB::table($table)->insert([...$row, $field => $other[$key]]);
        });
    }

    public static function foreignFields(): iterable
    {
        foreach (['bill_id' => 'bill', 'charge_id' => 'charge', 'organization_id' => 'organization', 'participant_id' => 'participant'] as $field => $key) {
            yield ['assessment_bill_items', $field, $key];
        }
        foreach (['charge_id' => 'charge', 'assessment_participant_id' => 'attempt', 'organization_id' => 'organization', 'participant_id' => 'participant'] as $field => $key) {
            yield ['assessment_entitlements', $field, $key];
        }
    }

    public function test_self_cannot_pay_for_another_participant_in_same_branch(): void
    {
        $this->expectException(QueryException::class);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $self = Fixture::create('self');
            $other = Fixture::create('self', ['organization' => $self['organization']]);
            DB::table('assessment_bill_items')->insert([...Fixture::item($other), 'bill_id' => $self['bill']]);
        });
    }

    public function test_self_bill_cannot_hold_two_attempts_of_same_participant(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');
        app(RlsContextRunner::class)->runAsService(function (): void {
            $self = Fixture::create('self');
            $other = Fixture::create('self', $self);
            DB::table('assessment_bill_items')->insert(Fixture::item($self));
            DB::table('assessment_bill_items')->insert([...Fixture::item($other), 'bill_id' => $self['bill']]);
        });
    }

    public function test_copying_bill_payer_does_not_authorize_another_participants_charge(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        app(RlsContextRunner::class)->runAsService(function (): void {
            $self = Fixture::create('self');
            $other = Fixture::create('self', ['organization' => $self['organization']]);
            DB::table('assessment_bill_items')->insert([...Fixture::item($other), 'bill_id' => $self['bill'],
                'payer_participant_id' => $self['participant']]);
        });
    }

    public function test_supported_entitlement_states_and_test_types_are_storable(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            foreach (['ist', 'papi', 'rmib', 'kraepelin', 'dass21'] as $type) {
                DB::table('assessment_entitlements')->insert([...Fixture::entitlement($this->fixture), 'test_type' => $type]);
            }
            $row = DB::table('assessment_entitlements')->where('test_type', 'ist');
            $this->assertSame(1, $row->update(['status' => 'ready', 'ready_at' => '2026-08-31 01:00:00+00']));
            $this->assertSame(1, $row->update(['status' => 'in_progress', 'started_at' => '2026-08-31 02:00:00+00']));
            $this->assertSame(1, $row->update(['status' => 'done', 'completed_at' => '2026-08-31 03:00:00+00']));
            $this->assertSame(4, DB::table('assessment_entitlements')->where('status', 'locked')->count());
        });
    }

    #[DataProvider('parentTables')]
    public function test_parent_delete_cannot_cascade_financial_history(string $table, string $key): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        app(RlsContextRunner::class)->runAsService(function () use ($table, $key): void {
            DB::table('assessment_bill_items')->insert(Fixture::item($this->fixture));
            DB::table('assessment_entitlements')->insert(Fixture::entitlement($this->fixture));
            DB::table($table)->where('id', $this->fixture[$key])->delete();
        });
    }

    public static function parentTables(): iterable
    {
        yield ['assessment_bills', 'bill'];
        yield ['assessment_charges', 'charge'];
    }

    #[DataProvider('childTables')]
    public function test_branch_cannot_create_claim_or_entitlement(string $table): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('42501');
        app(RlsContextRunner::class)->run(new RlsContext('branch_admin', $this->fixture['organization']),
            fn () => DB::table($table)->insert($table === 'assessment_bill_items'
                ? Fixture::item($this->fixture) : Fixture::entitlement($this->fixture)));
    }

    public static function childTables(): iterable
    {
        yield ['assessment_bill_items'];
        yield ['assessment_entitlements'];
    }

    public function test_collective_bill_accepts_multiple_participants_but_does_not_unlock_access(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $other = Fixture::create('organization', ['organization' => $this->fixture['organization']]);
            DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update(['amount' => 200, 'item_count' => 2]);
            foreach ([$this->fixture, $other] as $fixture) {
                DB::table('assessment_bill_items')->insert([...Fixture::item($fixture), 'bill_id' => $this->fixture['bill']]);
                DB::table('assessment_entitlements')->insert(Fixture::entitlement($fixture));
            }
            $this->assertSame(2, DB::table('assessment_bill_items')->where('bill_id', $this->fixture['bill'])->count());
            $this->assertSame(200, (int) DB::table('assessment_bill_items')->sum('amount'));
            $this->assertSame(2, DB::table('assessment_entitlements')->where('status', 'locked')->count());
        });
    }

    public function test_claim_survives_rejected_bill_and_cannot_be_taken_again(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_bill_items')->insert(Fixture::item($this->fixture));
            DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update(['status' => 'rejected']);
            $other = Fixture::create('organization', $this->fixture);
            DB::table('assessment_bill_items')->insert([...Fixture::item($this->fixture), 'bill_id' => $other['bill']]);
        });
    }

    public function test_parent_price_cannot_diverge_from_existing_item(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_bill_items')->insert(Fixture::item($this->fixture));
            DB::table('assessment_charges')->where('id', $this->fixture['charge'])->update(['base_amount' => 101, 'amount' => 101]);
        });
    }

    public function test_entitlement_duplicate_rejected_but_other_attempt_allowed(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_entitlements')->insert(Fixture::entitlement($this->fixture));
            $other = Fixture::create('organization', $this->fixture);
            DB::table('assessment_entitlements')->insert(Fixture::entitlement($other));
            $this->assertSame(2, DB::table('assessment_entitlements')->count());
            DB::table('assessment_entitlements')->insert(Fixture::entitlement($this->fixture));
        });
    }

    public function test_new_tables_keep_forced_rls_with_p6c_tenant_reads(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('assessment_bill_items')->insert(Fixture::item($this->fixture));
            DB::table('assessment_entitlements')->insert(Fixture::entitlement($this->fixture));
        });
        foreach (['assessment_bill_items', 'assessment_entitlements'] as $table) {
            DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
            $this->assertNull(DB::selectOne('SELECT app_private.app_role() AS role')->role);
            $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', [$table]);
            $this->assertTrue($security->relrowsecurity);
            $this->assertTrue($security->relforcerowsecurity);
            $this->assertSame(0, DB::table($table)->count());
            foreach (['participant', 'branch_admin', 'super_admin'] as $role) {
                $expected = $role === 'participant' && $table === 'assessment_bill_items' ? 0 : 1;
                app(RlsContextRunner::class)->run(new RlsContext($role, $this->fixture['organization'], $this->fixture['participant']),
                    fn () => $this->assertSame($expected, DB::table($table)->count()));
            }
        }
    }
}
