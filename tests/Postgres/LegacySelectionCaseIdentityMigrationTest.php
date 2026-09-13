<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\GenericResultLedgerMigrationFixture;

final class LegacySelectionCaseIdentityMigrationTest extends TestCase
{
    public function test_owner_backfills_and_enforces_exact_legacy_case_identity(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                $this->migrate('down');
                foreach (['branches', 'participants', 'selection_participants', 'assessment_cases'] as $table) {
                    DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                }
                $graph = $this->participant('history');
                $selection = DB::table('selection_participants')->insertGetId([
                    'client_id' => 'legacy-client', 'external_candidate_id' => 'candidate-history',
                    'selection_round_id' => 'round-history', 'registration_id' => 'registration-history',
                    'participant_id' => $graph['participant'], 'idempotency_key' => 'key-history',
                    'request_hash' => hash('sha256', 'history'),
                    'created_at' => '2026-08-29 03:15:00+00', 'updated_at' => '2026-08-29 03:15:00+00',
                ]);

                $this->migrate('up');

                $case = DB::table('assessment_cases')->where('id', DB::table('selection_participants')
                    ->where('id', $selection)->value('assessment_case_id'))->first();
                $this->assertTrue(Str::isUlid($case->public_id));
                $this->assertSame($graph['participant'], $case->participant_id);
                $this->assertSame($graph['branch'], $case->organization_id);
                $this->assertNull($case->package_id);
                $this->assertNull($case->intended_field_snapshot);
                $this->assertSame('LEGACY_SELECTION', $case->origin);
                $this->assertTrue((bool) DB::scalar(<<<'SQL'
                    SELECT attnotnull FROM pg_attribute
                    WHERE attrelid='selection_participants'::regclass AND attname='assessment_case_id'
                    SQL));
                $this->assertSame('assessment_case_id,participant_id', DB::selectOne(<<<'SQL'
                    SELECT string_agg(attribute.attname, ',' ORDER BY key.ordinality) columns
                    FROM pg_constraint con
                    CROSS JOIN LATERAL unnest(con.conkey) WITH ORDINALITY key(attnum, ordinality)
                    JOIN pg_attribute attribute ON attribute.attrelid=con.conrelid AND attribute.attnum=key.attnum
                    WHERE con.conrelid='selection_participants'::regclass
                      AND con.conname='selection_participants_case_scope_fk'
                    GROUP BY con.oid
                    SQL)->columns);
                foreach (['selection_participants', 'assessment_cases'] as $table) {
                    $security = DB::selectOne('SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table]);
                    $this->assertTrue($security->relrowsecurity, $table);
                    $this->assertTrue($security->relforcerowsecurity, $table);
                }
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_runtime_guard_rejects_wrong_origin_and_rebinding(): void
    {
        DB::beginTransaction();
        try {
            app(RlsContextRunner::class)->runAsService(function (): void {
                $first = $this->participant('first');
                $second = $this->participant('second');
                $legacy = $this->case($first, 'LEGACY_SELECTION');
                $direct = $this->case($second, 'DIRECT_PUBLIC');
                $selection = $this->selection($first['participant'], $legacy, 'first');

                $this->assertSqlState('23514', fn () => $this->selection($second['participant'], $direct, 'wrong'));
                $this->assertSqlState('23514', fn () => $this->selection($second['participant'], PHP_INT_MAX, 'missing'));
                foreach ($this->identityMutations($second['participant'], $direct) as $column => $value) {
                    $this->assertSqlState('P0001', fn () => DB::table('selection_participants')
                        ->where('id', $selection)->update([$column => $value]));
                }
                $case = DB::table('selection_participants')->where('id', $selection)->value('assessment_case_id');
                $this->assertSqlState('P0001', fn () => DB::table('selection_participants')
                    ->where('id', $selection)->delete());
                $this->assertSame($case, DB::table('selection_participants')->where('id', $selection)
                    ->value('assessment_case_id'));
                $this->assertSame(1, DB::table('assessment_cases')->where('id', $case)->count());
            });
        } finally {
            DB::rollBack();
        }
    }

    /** @return array{branch:int,participant:int} */
    private function participant(string $suffix): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'name' => $suffix, 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => $suffix,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'manual',
            'source_system' => 'SELEKSI_BEASISWA_JEPANG', 'full_name' => $suffix,
            'intended_field' => 'UMUM', 'phone' => '620000000000',
        ]);

        return compact('branch', 'participant');
    }

    /** @param array{branch:int,participant:int} $graph */
    private function case(array $graph, string $origin): int
    {
        return DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $graph['participant'],
            'organization_id' => $graph['branch'], 'package_id' => null, 'origin' => $origin,
            'intended_field_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function selection(int $participant, int $case, string $suffix): int
    {
        return DB::table('selection_participants')->insertGetId([
            'client_id' => 'client-'.$suffix, 'external_candidate_id' => 'candidate-'.$suffix,
            'selection_round_id' => 'round-'.$suffix, 'registration_id' => 'registration-'.$suffix,
            'participant_id' => $participant, 'assessment_case_id' => $case,
            'idempotency_key' => 'key-'.$suffix, 'request_hash' => hash('sha256', $suffix),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, int|string> */
    private function identityMutations(int $participant, int $case): array
    {
        return [
            'client_id' => 'mutated-client',
            'external_candidate_id' => 'mutated-candidate',
            'selection_round_id' => 'mutated-round',
            'registration_id' => 'mutated-registration',
            'participant_id' => $participant,
            'assessment_case_id' => $case,
            'idempotency_key' => 'mutated-key',
            'request_hash' => hash('sha256', 'mutated'),
            'created_at' => '2026-08-30 03:15:00+00',
        ];
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

    private function migrate(string $direction): void
    {
        if ($direction === 'down' && Schema::hasTable('test_session_grants')) {
            (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->down();
        }
        $directPublic = require database_path('migrations/2026_09_09_000600_bind_direct_public_orders_to_assessment_cases.php');
        $migration = require database_path('migrations/2026_09_09_000500_bind_legacy_selection_assessment_cases.php');
        if ($direction === 'down' && Schema::hasColumn('orders', 'assessment_case_id')) {
            $directPublic->down();
        }
        $operation = [$migration, $direction];
        if (! is_callable($operation)) {
            throw new \RuntimeException("Migration operation {$direction} is unavailable.");
        }
        $operation();
        if ($direction === 'up' && ! Schema::hasColumn('orders', 'assessment_case_id')) {
            $directPublic->up();
        }
        if ($direction === 'up' && ! Schema::hasTable('test_session_grants')) {
            (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->up();
        }
    }

    private function asOwner(callable $operation): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.legacy_selection_case_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('legacy_selection_case_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            GenericResultLedgerMigrationFixture::withoutLedger($operation);
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('legacy_selection_case_owner');
            config()->set('database.connections.legacy_selection_case_owner', null);
        }
    }
}
