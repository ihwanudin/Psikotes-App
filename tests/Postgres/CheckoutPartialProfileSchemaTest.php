<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class CheckoutPartialProfileSchemaTest extends TestCase
{
    private const FIELDS = ['full_name', 'gender', 'birth_date', 'education_level', 'intended_field', 'phone'];

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_runtime_can_store_partial_profiles_without_opening_access_or_losing_tenant_isolation(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        foreach (['participants', 'assessment_participants'] as $table) {
            $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) AS owner FROM pg_class WHERE oid = to_regclass(?)', [$table]);
            $this->assertNotSame($role->name, $security->owner);
            $this->assertTrue($security->relrowsecurity);
            $this->assertTrue($security->relforcerowsecurity);
        }
        [$f, $foreign] = app(RlsContextRunner::class)->runAsService(function (): array {
            $f = Fixture::create();
            $row = DB::table('participants')->find($f['participant']);
            foreach (self::FIELDS as $field) {
                DB::table('participants')->where('id', $f['participant'])->update([$field => null]);
                try {
                    app(AssessmentEntitlementGate::class)->assertReady(new AssessmentPrincipal($f['participant'], $f['organization'], $f['attempt']), 'ist');
                    $this->fail('Partial profile authorized access.');
                } catch (EntitlementLocked) {
                    $this->assertNull(DB::table('assessment_entitlements')->where('id', $f['entitlement'])->value('started_at'));
                }
                DB::table('participants')->where('id', $f['participant'])->update([$field => $row->{$field}]);
            }
            DB::table('participants')->where('id', $f['participant'])->update(array_fill_keys(self::FIELDS, null));
            foreach (['PROVISIONED', 'REVOKED', 'VOID'] as $status) {
                DB::table('assessment_participants')->where('id', $f['attempt'])->update(['assessment_status' => $status, 'funding_mode' => null]);
                $this->assertNull(DB::table('assessment_participants')->where('id', $f['attempt'])->value('funding_mode'));
            }
            $this->assertSame(0, DB::table('outbox_messages')->where('aggregate_id', (string) $f['attempt'])->count());

            return [$f, Fixture::create()];
        });
        app(RlsContextRunner::class)->run(new RlsContext('branch_admin', $f['organization']), function () use ($f, $foreign): void {
            foreach (['participants' => 'participant', 'assessment_participants' => 'attempt'] as $table => $key) {
                $this->assertSame(1, DB::table($table)->where('id', $f[$key])->count());
                $this->assertSame(0, DB::table($table)->where('id', $foreign[$key])->count());
            }
            $this->assertSame(0, DB::table('assessment_participants')->where('id', $f['attempt'])->update(['funding_mode' => 'COMMERCIAL_SELF_PAY']));
        });
        app(RlsContextRunner::class)->run(new RlsContext('branch_admin', $foreign['organization']), function () use ($f): void {
            $this->assertSame(0, DB::table('assessment_participants')->where('id', $f['attempt'])->count());
        });
    }

    #[DataProvider('invalidFunding')]
    public function test_runtime_check_rejects_unknown_marker_or_invalid_status(?string $metadata, string $status): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        app(RlsContextRunner::class)->runAsService(function () use ($metadata, $status): void {
            $f = Fixture::create();
            DB::table('assessment_participants')->where('id', $f['attempt'])->update([
                'funding_mode' => null, 'metadata' => $metadata, 'assessment_status' => $status,
            ]);
        });
    }

    public static function invalidFunding(): iterable
    {
        foreach (['PROVISIONED', 'REVOKED', 'VOID'] as $status) {
            foreach ([null, '{}', 'null', '{"checkout_contract_version":null}', '{"checkout_contract_version":"v1"}',
                '{"checkout_contract_version":true}', '[]', '{"checkout_contract_version":["checkout-v2"]}'] as $i => $metadata) {
                yield $status.' marker '.$i => [$metadata, $status];
            }
        }
        foreach (['READY', 'IN_PROGRESS', 'COMPLETED', 'UNDER_REVIEW', 'FINALIZED'] as $status) {
            yield $status => ['{"checkout_contract_version":"checkout-v2"}', $status];
        }
    }

    public function test_existing_unselected_attempt_cannot_be_promoted_or_lose_its_marker(): void
    {
        $f = app(RlsContextRunner::class)->runAsService(function (): array {
            $f = Fixture::create();
            DB::table('assessment_participants')->where('id', $f['attempt'])->update(['funding_mode' => null, 'assessment_status' => 'PROVISIONED']);

            return $f;
        });
        foreach ([['assessment_status' => 'READY'], ['metadata' => null], ['metadata' => '{}']] as $changes) {
            try {
                app(RlsContextRunner::class)->runAsService(fn () => DB::table('assessment_participants')->where('id', $f['attempt'])->update($changes));
                $this->fail('Invalid transition was accepted.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
        app(RlsContextRunner::class)->runAsService(function () use ($f): void {
            $attempt = DB::table('assessment_participants')->find($f['attempt']);
            $this->assertSame('PROVISIONED', $attempt->assessment_status);
            $this->assertNull($attempt->funding_mode);
            $this->assertSame('checkout-v2', json_decode($attempt->metadata, true)['checkout_contract_version']);
            // Explicit non-null funding keeps existing legacy storage semantics; no ready right is created here.
            DB::table('assessment_participants')->where('id', $f['attempt'])->update(['funding_mode' => 'COMMERCIAL_SELF_PAY', 'metadata' => null]);
            $this->assertSame('COMMERCIAL_SELF_PAY', DB::table('assessment_participants')->where('id', $f['attempt'])->value('funding_mode'));
        });
    }

    public function test_insert_constraint_is_enforced_as_well_as_update(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        app(RlsContextRunner::class)->runAsService(function (): void {
            $f = Fixture::create();
            $row = (array) DB::table('assessment_participants')->find($f['attempt']);
            unset($row['id']);
            DB::table('assessment_participants')->insert([...$row, 'assessment_attempt_id' => (string) Str::ulid(),
                'idempotency_key' => 'p9a0-insert', 'logical_assessment_key' => hash('sha256', 'p9a0-insert'),
                'funding_mode' => null, 'metadata' => null, 'assessment_status' => 'PROVISIONED']);
        });
    }

    #[DataProvider('existingConstraints')]
    public function test_existing_enum_length_and_foreign_key_constraints_still_apply(string $table, string $key, array $changes, string $sqlstate): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode($sqlstate);
        app(RlsContextRunner::class)->runAsService(function () use ($table, $key, $changes): void {
            $f = Fixture::create();
            DB::table($table)->where('id', $f[$key])->update($changes);
        });
    }

    public static function existingConstraints(): iterable
    {
        yield 'gender enum' => ['participants', 'participant', ['gender' => 'unknown'], '23514'];
        yield 'field enum' => ['participants', 'participant', ['intended_field' => 'unknown'], '23514'];
        yield 'name length' => ['participants', 'participant', ['full_name' => str_repeat('x', 201)], '22001'];
        yield 'phone length' => ['participants', 'participant', ['phone' => str_repeat('1', 33)], '22001'];
        yield 'participant FK' => ['assessment_participants', 'attempt', ['participant_id' => 999999999], '23503'];
        yield 'package FK' => ['assessment_participants', 'attempt', ['package_id' => 999999999], '23503'];
    }

    public function test_owner_ddl_roundtrip_and_preflight_preserve_data_and_all_existing_controls(): void
    {
        // DDL owner use is restricted to the disposable runner; authorization tests above use runtime.
        $this->assertFileExists('/.dockerenv');
        $this->assertSame('testing', app()->environment());
        $runId = getenv('ORG_TEST_RUN_ID');
        $this->assertIsString($runId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $runId);
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        $this->assertSame('org-test-db', $config['host']);
        $this->assertSame('psikotes_organization_test', $config['database']);
        $marker = DB::selectOne("SELECT shobj_description(oid, 'pg_database') AS marker FROM pg_database WHERE datname = current_database()");
        $this->assertSame('ONCAM_ORG_TEST:'.$runId, $marker->marker);
        config()->set('database.connections.partial_profile_ddl_test', [...$config, 'username' => 'org_test_owner']);
        $owner = DB::connection('partial_profile_ddl_test');
        try {
            $owner->beginTransaction();
            DB::setDefaultConnection('partial_profile_ddl_test');
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $f = Fixture::create();
            $participant = DB::table('participants')->find($f['participant']);
            $attempt = DB::table('assessment_participants')->find($f['attempt']);
            $structure = $this->structure();
            $this->migration()->down();
            $required = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'participants' AND is_nullable = 'NO'");
            foreach (self::FIELDS as $field) {
                $this->assertContains($field, array_column($required, 'column_name'));
            }
            $this->assertSame('NO', DB::table('information_schema.columns')->where('table_name', 'assessment_participants')->where('column_name', 'funding_mode')->value('is_nullable'));
            $this->migration()->up();
            $this->assertEquals($structure, $this->structure());
            $this->assertEquals($participant, DB::table('participants')->find($f['participant']));
            $this->assertEquals($attempt, DB::table('assessment_participants')->find($f['attempt']));
            foreach ([...self::FIELDS, 'funding_mode'] as $field) {
                $table = $field === 'funding_mode' ? 'assessment_participants' : 'participants';
                $id = $field === 'funding_mode' ? $f['attempt'] : $f['participant'];
                DB::table($table)->where('id', $id)->update([$field => null, ...($field === 'funding_mode' ? ['assessment_status' => 'PROVISIONED'] : [])]);
                $before = DB::table($table)->find($id);
                try {
                    $this->migration()->down();
                    $this->fail('Rollback changed incomplete data.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('prevent rollback', $exception->getMessage());
                }
                $this->assertEquals($before, DB::table($table)->find($id));
                $this->assertEquals($structure, $this->structure());
                DB::table($table)->where('id', $id)->update([$field => ($field === 'funding_mode' ? $attempt : $participant)->{$field}]);
            }
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            DB::setDefaultConnection($runtime);
            DB::purge('partial_profile_ddl_test');
            config()->set('database.connections.partial_profile_ddl_test', null);
        }
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
    }

    public function test_rollback_refuses_rls_filtered_preflight_without_disabling_runtime_security(): void
    {
        $f = app(RlsContextRunner::class)->runAsService(function (): array {
            $f = Fixture::create();
            DB::table('participants')->where('id', $f['participant'])->update(['full_name' => null]);

            return $f;
        });
        try {
            $this->migration()->down();
            $this->fail('Runtime may not perform a filtered rollback preflight.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
            $this->assertStringContainsString('row-level security', $exception->getMessage());
        }
        $this->assertSame('on', DB::scalar('SHOW row_security'));
        app(RlsContextRunner::class)->runAsService(function () use ($f): void {
            $this->assertNull(DB::table('participants')->where('id', $f['participant'])->value('full_name'));
            $this->assertSame(1, DB::table('pg_constraint')->where('conname', 'assessment_participants_checkout_funding_check')->count());
        });
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_31_000600_allow_checkout_partial_profiles.php');
    }

    private function structure(): array
    {
        $result = [];
        foreach (['participants', 'assessment_participants'] as $table) {
            $result[$table] = [
                DB::select('SELECT attname, format_type(atttypid, atttypmod) AS type, attnotnull, pg_get_expr(adbin, adrelid) AS default_value
                    FROM pg_attribute LEFT JOIN pg_attrdef ON adrelid = attrelid AND adnum = attnum
                    WHERE attrelid = to_regclass(?) AND attnum > 0 AND NOT attisdropped ORDER BY attnum', [$table]),
                DB::select('SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = to_regclass(?) ORDER BY conname', [$table]),
                DB::select('SELECT indexname, indexdef FROM pg_indexes WHERE tablename = ? ORDER BY indexname', [$table]),
                DB::select('SELECT * FROM pg_policies WHERE tablename = ? ORDER BY policyname', [$table]),
                DB::select('SELECT relrowsecurity, relforcerowsecurity, relowner FROM pg_class WHERE oid = to_regclass(?)', [$table]),
            ];
        }

        return $result;
    }
}
