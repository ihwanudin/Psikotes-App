<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TestSessionCaseIdentityMigrationTest extends TestCase
{
    public function test_owner_backfills_one_candidate_keeps_zero_nullable_and_preserves_contracts(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                $this->migrate('down');
                $this->openFixtureTables();
                $unique = $this->graph('unique');
                $case = $this->createCase($unique);
                $session = $this->createSession($unique['participant']);
                $zero = $this->graph('zero');
                $zeroSession = $this->createSession($zero['participant']);

                $this->migrate('up');
                $this->migrate('up');

                $this->assertSame($case, DB::table('test_sessions')->where('id', $session)->value('assessment_case_id'));
                $this->assertNull(DB::table('test_sessions')->where('id', $zeroSession)->value('assessment_case_id'));
                $this->assertFalse((bool) DB::scalar(<<<'SQL'
                    SELECT attnotnull FROM pg_attribute
                    WHERE attrelid='test_sessions'::regclass AND attname='assessment_case_id'
                    SQL));
                $this->assertConstraint('assessment_cases_session_scope_unique', 'u');
                $this->assertConstraint('test_sessions_case_scope_fk', 'f');
                foreach (array_keys($this->caseIndexes()) as $name) {
                    $this->assertTrue((bool) DB::scalar('SELECT to_regclass(?) IS NOT NULL', [$name]), $name);
                }
                foreach (['assessment_cases', 'test_sessions'] as $table) {
                    $security = DB::selectOne(
                        'SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table],
                    );
                    $this->assertTrue($security->relrowsecurity, $table);
                    $this->assertTrue($security->relforcerowsecurity, $table);
                }
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_ambiguous_and_mismatched_history_abort_atomically(): void
    {
        foreach (['ambiguous', 'mismatch'] as $scenario) {
            $this->asOwner(function () use ($scenario): void {
                DB::beginTransaction();
                try {
                    $this->migrate('down');
                    $this->openFixtureTables();
                    $unique = $this->graph($scenario.'-unique');
                    $this->createCase($unique);
                    $uniqueSession = $this->createSession($unique['participant']);
                    $invalid = $this->graph($scenario.'-invalid');
                    $invalidSession = $this->createSession($invalid['participant']);
                    if ($scenario === 'ambiguous') {
                        $this->createCase($invalid);
                        $this->createCase($invalid);
                    } else {
                        $other = $this->graph('other');
                        $wrong = $this->createCase($other);
                        DB::table('test_sessions')->where('id', $invalidSession)->update(['assessment_case_id' => $wrong]);
                    }

                    try {
                        $this->migrate('up');
                        $this->fail('Invalid history must abort.');
                    } catch (RuntimeException $exception) {
                        $this->assertStringStartsWith('Test session case backfill aborted:', $exception->getMessage());
                    }
                    $this->assertNull(DB::table('test_sessions')->where('id', $uniqueSession)->value('assessment_case_id'));
                    $this->assertNull(DB::selectOne(
                        "SELECT conname FROM pg_constraint WHERE conname='test_sessions_case_scope_fk' AND conrelid='test_sessions'::regclass",
                    ));
                } finally {
                    DB::rollBack();
                }
            });
        }
    }

    public function test_runtime_is_nonbypass_and_guards_exact_immutable_identity_without_blocking_lifecycle(): void
    {
        $identity = DB::selectOne('SELECT rolsuper,rolbypassrls FROM pg_roles WHERE rolname=current_user');
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);

        DB::beginTransaction();
        try {
            app(RlsContextRunner::class)->runAsService(function (): void {
                $graph = $this->graph('runtime');
                $case = $this->createCase($graph);
                $other = $this->graph('other');
                $otherCase = $this->createCase($other);
                $zero = $this->graph('zero');
                $session = $this->createSession($graph['participant'], $case);
                $nullable = $this->createSession($zero['participant']);
                $started = now();
                DB::table('test_sessions')->where('id', $session)->update([
                    'status' => 'in_progress', 'started_at' => $started,
                    'ends_at' => $started->copy()->addHour(),
                ]);
                $this->assertSame('in_progress', DB::table('test_sessions')->where('id', $session)->value('status'));
                $this->assertNull(DB::table('test_sessions')->where('id', $nullable)->value('assessment_case_id'));
                $this->assertSqlState('23514', fn () => $this->createSession($other['participant']));
                $this->assertSqlState('23514', fn () => $this->createSession($graph['participant'], $otherCase, 2));
                $this->assertSqlState('P0001', fn () => DB::table('test_sessions')->where('id', $session)
                    ->update(['assessment_case_id' => null]));
                $this->assertSqlState('P0001', fn () => DB::table('test_sessions')->where('id', $session)
                    ->update(['created_at' => now()->addSecond()]));
            });
        } finally {
            DB::rollBack();
        }
    }

    public function test_owner_populated_down_refuses_without_schema_or_force_rls_delta(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                $directPublic = require database_path('migrations/2026_09_09_000600_bind_direct_public_orders_to_assessment_cases.php');
                $sessionCase = require database_path('migrations/2026_09_09_000400_harden_test_session_case_identity.php');
                (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->down();
                $directPublic->down();
                $before = $this->definitions();
                $this->openFixtureTables();
                $graph = $this->graph('populated');
                $case = $this->createCase($graph);
                $session = $this->createSession($graph['participant'], $case);
                DB::statement('ALTER TABLE assessment_cases FORCE ROW LEVEL SECURITY');
                DB::statement('ALTER TABLE test_sessions FORCE ROW LEVEL SECURITY');
                try {
                    $sessionCase->down();
                    $this->fail('Populated history must refuse rollback.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Test session case history prevents rollback.', $exception->getMessage());
                }
                $this->assertSame($case, DB::table('test_sessions')->where('id', $session)->value('assessment_case_id'));
                $this->assertEquals($before, $this->definitions());
            } finally {
                DB::rollBack();
            }
        });
    }

    #[DataProvider('postgresCorruptions')]
    public function test_rerun_rejects_each_wrong_postgres_definition_without_delta(string $component): void
    {
        $this->asOwner(function () use ($component): void {
            DB::beginTransaction();
            try {
                $directPublic = require database_path('migrations/2026_09_09_000600_bind_direct_public_orders_to_assessment_cases.php');
                $legacySelection = require database_path('migrations/2026_09_09_000500_bind_legacy_selection_assessment_cases.php');
                (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->down();
                $directPublic->down();
                $legacySelection->down();
                $this->corruptPostgres($component);
                $before = $this->definitions();
                try {
                    $this->migrate('up');
                    $this->fail("Wrong {$component} definition must be rejected.");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('partial PostgreSQL enforcement', $exception->getMessage());
                }
                $this->assertEquals($before, $this->definitions());
                foreach (['assessment_cases', 'test_sessions'] as $table) {
                    $this->assertTrue((bool) DB::scalar(
                        'SELECT relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table],
                    ));
                }
            } finally {
                DB::rollBack();
            }
        });
    }

    /** @return iterable<string,array{string}> */
    public static function postgresCorruptions(): iterable
    {
        yield 'parent unique order' => ['parent_unique'];
        yield 'foreign source columns' => ['foreign_source'];
        yield 'foreign referenced columns' => ['foreign_reference'];
        yield 'foreign delete action' => ['foreign_delete'];
        yield 'foreign update action' => ['foreign_update'];
        yield 'foreign match type' => ['foreign_match'];
        yield 'foreign deferrability' => ['foreign_deferrable'];
        yield 'case index columns' => ['index_columns'];
        yield 'case index predicate' => ['index_predicate'];
        yield 'active case index predicate' => ['active_index_predicate'];
        yield 'guard event' => ['guard_event'];
        yield 'guard function' => ['guard_function'];
        yield 'guard disabled' => ['guard_disabled'];
        yield 'guard security definer' => ['guard_security'];
        yield 'guard unsafe search path' => ['guard_search_path'];
        yield 'guard body' => ['guard_body'];
    }

    private function migrate(string $direction): void
    {
        if ($direction === 'down' && Schema::hasTable('test_session_grants')) {
            (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->down();
        }
        $directPublic = require database_path('migrations/2026_09_09_000600_bind_direct_public_orders_to_assessment_cases.php');
        $legacySelection = require database_path('migrations/2026_09_09_000500_bind_legacy_selection_assessment_cases.php');
        $migration = require database_path('migrations/2026_09_09_000400_harden_test_session_case_identity.php');
        if ($direction === 'down' && Schema::hasColumn('orders', 'assessment_case_id')) {
            $directPublic->down();
        }
        if ($direction === 'down' && Schema::hasColumn('selection_participants', 'assessment_case_id')) {
            $legacySelection->down();
        }
        $operation = [$migration, $direction];
        if (! is_callable($operation)) {
            throw new RuntimeException("Migration operation {$direction} is unavailable.");
        }
        $operation();
        if ($direction === 'up' && ! Schema::hasColumn('selection_participants', 'assessment_case_id')) {
            $legacySelection->up();
        }
        if ($direction === 'up' && ! Schema::hasColumn('orders', 'assessment_case_id')) {
            $directPublic->up();
        }
        if ($direction === 'up' && ! Schema::hasTable('test_session_grants')) {
            (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->up();
        }
    }

    private function openFixtureTables(): void
    {
        DB::statement('ALTER TABLE assessment_cases NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE test_sessions NO FORCE ROW LEVEL SECURITY');
    }

    /** @return array{branch:int,package:int,participant:int} */
    private function graph(string $suffix): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $suffix,
            'organization_code' => $key, 'display_name' => $suffix,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => $suffix, 'amount' => 0, 'currency' => 'IDR',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'package_id' => $package, 'source_system' => 'P4_TEST',
            'full_name' => $suffix, 'phone' => '620000000000',
        ]);

        return compact('branch', 'package', 'participant');
    }

    /** @param array{branch:int,package:int,participant:int} $graph */
    private function createCase(array $graph): int
    {
        return DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $graph['participant'],
            'organization_id' => $graph['branch'], 'package_id' => $graph['package'],
            'origin' => 'INTEGRATED', 'intended_field_snapshot' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createSession(int $participant, ?int $case = null, int $attempt = 1): int
    {
        return DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => $attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertConstraint(string $name, string $type): void
    {
        $constraint = DB::selectOne(
            'SELECT contype FROM pg_constraint WHERE conname=? AND conrelid=CASE WHEN ? LIKE ? THEN ?::regclass ELSE ?::regclass END',
            [$name, $name, 'assessment_cases%', 'assessment_cases', 'test_sessions'],
        );
        $this->assertNotNull($constraint, $name);
        $this->assertSame($type, $constraint->contype, $name);
    }

    /** @return array<string,bool> */
    private function caseIndexes(): array
    {
        return [
            'test_sessions_case_attempt_unique' => true,
            'test_sessions_case_authorization_unique' => true,
            'test_sessions_case_allocation_intent_unique' => true,
            'test_sessions_case_one_active_unique' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function definitions(): array
    {
        return [
            'constraints' => DB::select("SELECT conname,pg_get_constraintdef(oid) definition FROM pg_constraint WHERE conrelid IN ('assessment_cases'::regclass,'test_sessions'::regclass) AND conname IN ('assessment_cases_session_scope_unique','test_sessions_case_scope_fk') ORDER BY conname"),
            'indexes' => DB::select("SELECT indexname,indexdef FROM pg_indexes WHERE tablename='test_sessions' AND indexname LIKE 'test_sessions_case_%' ORDER BY indexname"),
            'trigger' => DB::select("SELECT pg_get_triggerdef(oid) definition FROM pg_trigger WHERE tgrelid='test_sessions'::regclass AND tgname='test_sessions_case_identity_guard'"),
            'function' => DB::select("SELECT pg_get_functiondef(p.oid) definition FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='app_private' AND p.proname IN ('guard_test_session_case_identity','synthetic_session_guard') ORDER BY p.proname"),
            'force' => DB::select("SELECT relname,relforcerowsecurity FROM pg_class WHERE oid IN ('assessment_cases'::regclass,'test_sessions'::regclass) ORDER BY relname"),
        ];
    }

    private function corruptPostgres(string $component): void
    {
        if ($component === 'parent_unique') {
            DB::statement('ALTER TABLE test_sessions DROP CONSTRAINT test_sessions_case_scope_fk');
            DB::statement('ALTER TABLE assessment_cases ADD CONSTRAINT synthetic_session_scope_unique UNIQUE (id, participant_id)');
            DB::statement('ALTER TABLE assessment_cases DROP CONSTRAINT assessment_cases_session_scope_unique');
            DB::statement('ALTER TABLE assessment_cases ADD CONSTRAINT assessment_cases_session_scope_unique UNIQUE (participant_id, id)');
            DB::statement('ALTER TABLE test_sessions ADD CONSTRAINT test_sessions_case_scope_fk FOREIGN KEY (assessment_case_id, participant_id) REFERENCES assessment_cases (id, participant_id) ON DELETE RESTRICT');

            return;
        }
        if (str_starts_with($component, 'foreign_')) {
            DB::statement('ALTER TABLE test_sessions DROP CONSTRAINT test_sessions_case_scope_fk');
            if ($component === 'foreign_source' || $component === 'foreign_reference') {
                DB::statement('ALTER TABLE assessment_cases ADD CONSTRAINT synthetic_session_scope_unique UNIQUE (participant_id, id)');
            }
            $source = $component === 'foreign_source'
                ? '(participant_id, assessment_case_id)'
                : '(assessment_case_id, participant_id)';
            $reference = $component === 'foreign_reference'
                ? '(participant_id, id)'
                : ($component === 'foreign_source' ? '(participant_id, id)' : '(id, participant_id)');
            $suffix = match ($component) {
                'foreign_delete' => 'ON DELETE CASCADE',
                'foreign_update' => 'ON DELETE RESTRICT ON UPDATE CASCADE',
                'foreign_match' => 'MATCH FULL ON DELETE RESTRICT',
                'foreign_deferrable' => 'ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED',
                default => 'ON DELETE RESTRICT',
            };
            DB::statement("ALTER TABLE test_sessions ADD CONSTRAINT test_sessions_case_scope_fk FOREIGN KEY {$source} REFERENCES assessment_cases {$reference} {$suffix}");

            return;
        }
        if (str_starts_with($component, 'index_') || $component === 'active_index_predicate') {
            $index = $component === 'active_index_predicate'
                ? 'test_sessions_case_one_active_unique'
                : 'test_sessions_case_attempt_unique';
            DB::statement("DROP INDEX {$index}");
            $sql = $component === 'index_columns'
                ? 'CREATE UNIQUE INDEX test_sessions_case_attempt_unique ON test_sessions (assessment_case_id, attempt_no, test_type) WHERE assessment_case_id IS NOT NULL'
                : ($component === 'active_index_predicate'
                    ? "CREATE UNIQUE INDEX test_sessions_case_one_active_unique ON test_sessions (assessment_case_id,test_type) WHERE assessment_case_id IS NOT NULL AND status='created'"
                    : 'CREATE UNIQUE INDEX test_sessions_case_attempt_unique ON test_sessions (assessment_case_id, test_type, attempt_no) WHERE assessment_case_id IS NULL');
            DB::statement($sql);

            return;
        }

        if ($component === 'guard_disabled') {
            DB::statement('ALTER TABLE test_sessions DISABLE TRIGGER test_sessions_case_identity_guard');

            return;
        }
        if ($component === 'guard_security') {
            DB::statement('ALTER FUNCTION app_private.guard_test_session_case_identity() SECURITY DEFINER');

            return;
        }
        if ($component === 'guard_search_path') {
            DB::statement('ALTER FUNCTION app_private.guard_test_session_case_identity() SET search_path=public');

            return;
        }

        DB::unprepared('DROP TRIGGER test_sessions_case_identity_guard ON test_sessions');
        if ($component === 'guard_event') {
            DB::unprepared('CREATE TRIGGER test_sessions_case_identity_guard BEFORE INSERT ON test_sessions FOR EACH ROW EXECUTE FUNCTION app_private.guard_test_session_case_identity()');

            return;
        }
        if ($component === 'guard_function') {
            DB::unprepared('CREATE FUNCTION app_private.synthetic_session_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END; $$');
            DB::unprepared('CREATE TRIGGER test_sessions_case_identity_guard BEFORE INSERT OR UPDATE ON test_sessions FOR EACH ROW EXECUTE FUNCTION app_private.synthetic_session_guard()');

            return;
        }
        DB::unprepared('CREATE OR REPLACE FUNCTION app_private.guard_test_session_case_identity() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER test_sessions_case_identity_guard BEFORE INSERT OR UPDATE ON test_sessions FOR EACH ROW EXECUTE FUNCTION app_private.guard_test_session_case_identity()');
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

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.test_session_case_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('test_session_case_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('test_session_case_owner');
            config()->set('database.connections.test_session_case_owner', null);
        }
    }
}
