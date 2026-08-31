<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentBillingFixture as Fixture;

final class AssessmentBillingRlsTest extends TestCase
{
    private array $own;

    private array $peer;

    private array $foreign;

    private array $rows;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->own = Fixture::create('self');
            $this->peer = Fixture::create('organization', ['organization' => $this->own['organization']]);
            $this->foreign = Fixture::create();
            foreach ([$this->own, $this->peer, $this->foreign] as $fixture) {
                DB::table('assessment_bill_items')->insert(Fixture::item($fixture));
                DB::table('assessment_entitlements')->insert(Fixture::entitlement($fixture));
            }
            foreach (self::tables() as [$table]) {
                $this->rows[$table] = (array) DB::table($table)->where('organization_id', $this->own['organization'])->first();
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
    public function test_read_scope_for_all_roles(string $table): void
    {
        $runner = app(RlsContextRunner::class);
        foreach (['service' => 3, 'super_admin' => 3, 'branch_admin' => 2,
            'participant' => in_array($table, ['assessment_charges', 'assessment_entitlements'], true) ? 1 : 0,
            'staff' => 0, 'psychologist' => 0] as $role => $expected) {
            $runner->run(new RlsContext($role, $this->own['organization'], $this->own['participant']), function () use ($table, $role, $expected): void {
                $this->assertSame($expected, DB::table($table)->count(), $table.':'.$role);
                if (in_array($role, ['branch_admin', 'participant'], true)) {
                    $this->assertSame(0, DB::table($table)->where('organization_id', $this->foreign['organization'])->count());
                }
                if ($role === 'participant' && $expected === 1) {
                    $this->assertSame([$this->own['participant']], DB::table($table)->pluck('participant_id')->all());
                }
            });
        }
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
        $this->assertNull(DB::selectOne('SELECT app_private.app_role() AS role')->role);
        $this->assertSame(0, DB::table($table)->count());
        $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', [$table]);
        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
        $this->assertFalse(DB::selectOne("SELECT has_table_privilege(current_user, ?, 'TRUNCATE') AS allowed", [$table])->allowed);
    }

    public static function tables(): iterable
    {
        foreach (['assessment_bills', 'assessment_bill_items', 'assessment_charges', 'assessment_entitlements'] as $table) {
            yield [$table];
        }
    }

    #[DataProvider('userTables')]
    public function test_users_cannot_update_delete_or_insert_even_visible_rows(string $role, string $table): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('42501');
        app(RlsContextRunner::class)->run(new RlsContext($role, $this->own['organization'], $this->own['participant']), function () use ($table): void {
            $this->assertSame(0, DB::table($table)->where('organization_id', $this->own['organization'])->update(['updated_at' => now()]));
            $this->assertSame(0, DB::table($table)->where('organization_id', $this->own['organization'])->delete());
            // Use valid fixture fields, including for roles unable to SELECT the source.
            DB::table($table)->insert($this->rows[$table]);
        });
    }

    public static function userTables(): iterable
    {
        foreach (['participant', 'branch_admin', 'super_admin'] as $role) {
            foreach (self::tables() as [$table]) {
                yield [$role, $table];
            }
        }
    }

    public function test_participant_id_from_another_organization_cannot_cross_scope(): void
    {
        app(RlsContextRunner::class)->run(new RlsContext('participant', $this->own['organization'], $this->foreign['participant']), function (): void {
            foreach (self::tables() as [$table]) {
                $this->assertSame(0, DB::table($table)->count());
            }
        });
    }

    #[DataProvider('tables')]
    public function test_absent_context_cannot_write(string $table): void
    {
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
        $this->assertSame(0, DB::table($table)->update(['updated_at' => now()]));
        $this->assertSame(0, DB::table($table)->delete());
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('42501');
        DB::table($table)->insert($this->rows[$table]);
    }

    public function test_service_can_still_update_rows(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            foreach (self::tables() as [$table]) {
                $this->assertSame(3, DB::table($table)->update(['updated_at' => now()]));
            }
        });
    }
}
