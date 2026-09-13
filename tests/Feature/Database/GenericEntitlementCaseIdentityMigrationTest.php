<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class GenericEntitlementCaseIdentityMigrationTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->invokeMigration('2026_09_10_000500_contract_generic_entitlement_uniqueness.php', 'down');
        $this->invokeMigration('2026_09_10_000400_enforce_generic_entitlement_case_identity.php', 'down');
        $this->migrateDown();
    }

    protected function tearDown(): void
    {
        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        } finally {
            parent::tearDown();
        }
    }

    public function test_exact_direct_and_selection_entitlements_are_bound_while_dass_remains_unbound(): void
    {
        $direct = $this->directGraph('direct');
        $legacy = $this->legacyGraph('legacy');
        $this->insertDirectGrant($direct);
        $this->insertLegacyGrant($legacy);

        $this->migrateUp();

        $this->assertSame($direct['case'], DB::table('entitlements')->where('id', $direct['generic'])->value('assessment_case_id'));
        $this->assertNull(DB::table('entitlements')->where('id', $direct['dass'])->value('assessment_case_id'));
        $this->assertSame($legacy['case'], DB::table('entitlements')->where('id', $legacy['generic'])->value('assessment_case_id'));
        $this->assertSame([$direct['session'], $legacy['session']], DB::table('test_session_grants')->orderBy('test_session_id')->pluck('test_session_id')->all());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        $indexes = collect(DB::select("PRAGMA index_list('entitlements')"))->pluck('name')->all();
        $this->assertContains('entitlements_case_test_type_unique', $indexes);
        $this->assertContains('entitlements_case_grant_scope_unique', $indexes);
        $foreign = collect(DB::select("PRAGMA foreign_key_list('entitlements')"))
            ->filter(fn (object $row): bool => data_get($row, 'table') === 'assessment_cases')
            ->sortBy('seq')->values();
        $this->assertSame(['assessment_case_id', 'participant_id'], $foreign->pluck('from')->all());
        $this->assertSame(['id', 'participant_id'], $foreign->pluck('to')->all());
        $this->assertRejected(fn () => DB::table('entitlements')->insert(
            $this->entitlementRow($direct['participant'], null, 'ist'),
        ));
    }

    public function test_compatibility_null_is_allowed_but_dass_binding_cross_scope_and_rebinding_are_rejected(): void
    {
        $direct = $this->directGraph('guard');
        $other = $this->directGraph('other');
        $this->migrateUp();

        $compatible = $this->participant('compatible', $direct['branch'], $direct['package'], 'DIRECT_PUBLIC');
        DB::table('entitlements')->insert($this->entitlementRow($compatible, null, 'papi'));
        $this->assertDatabaseHas('entitlements', [
            'participant_id' => $compatible,
            'test_type' => 'papi',
            'assessment_case_id' => null,
        ]);

        $this->assertRejected(fn () => DB::table('entitlements')->where('id', $direct['dass'])
            ->update(['assessment_case_id' => $direct['case']]));
        $this->assertRejected(fn () => DB::table('entitlements')->where('id', $direct['generic'])
            ->update(['assessment_case_id' => $other['case']]));
        $this->assertRejected(fn () => DB::table('entitlements')->where('id', $direct['generic'])
            ->update(['assessment_case_id' => null]));
    }

    public function test_missing_exact_source_aborts_without_schema_delta(): void
    {
        $branch = $this->branch('invalid');
        $participant = $this->participant('invalid', $branch, null, 'RESULT_TEST');
        DB::table('entitlements')->insert($this->entitlementRow($participant, null, 'ist'));
        $before = $this->sqliteSchema();

        try {
            $this->migrateUp();
            $this->fail('Unmapped generic history must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exactly one durable case source', $exception->getMessage());
        }

        $this->assertSame($before, $this->sqliteSchema());
        $this->assertFalse(Schema::hasColumn('entitlements', 'assessment_case_id'));
    }

    #[DataProvider('invalidDirectCompositions')]
    public function test_inexact_direct_composition_aborts_atomically(string $corruption): void
    {
        $direct = $this->directGraph('composition-'.$corruption);
        match ($corruption) {
            'missing_dass' => DB::table('entitlements')->where('id', $direct['dass'])->delete(),
            'missing_generic' => DB::table('entitlements')->where('id', $direct['generic'])->delete(),
            'extra_entitlement' => DB::table('entitlements')->insert(
                $this->entitlementRow($direct['participant'], $direct['order'], 'papi'),
            ),
            'mismatched_entitlement' => DB::table('package_items')->where('package_id', $direct['package'])
                ->where('test_type', 'ist')->update(['test_type' => 'papi']),
            default => throw new RuntimeException('Unknown corruption probe.'),
        };
        $before = $this->sqliteSchema();

        try {
            $this->migrateUp();
            $this->fail('Inexact direct composition must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('direct package composition', $exception->getMessage());
        }

        $this->assertSame($before, $this->sqliteSchema());
        $this->assertFalse(Schema::hasColumn('entitlements', 'assessment_case_id'));
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidDirectCompositions(): iterable
    {
        yield 'missing DASS' => ['missing_dass'];
        yield 'missing generic' => ['missing_generic'];
        yield 'extra entitlement' => ['extra_entitlement'];
        yield 'mismatched entitlement' => ['mismatched_entitlement'];
    }

    public function test_bound_history_refuses_rollback_while_dass_only_history_survives_empty_contract_rollback(): void
    {
        $direct = $this->directGraph('rollback');
        $this->migrateUp();
        try {
            $this->migrateDown();
            $this->fail('Bound generic history must prevent rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Generic entitlement case history prevents rollback.', $exception->getMessage());
        }
        $this->assertSame($direct['case'], DB::table('entitlements')->where('id', $direct['generic'])->value('assessment_case_id'));

        DB::table('entitlements')->where('id', $direct['generic'])->delete();
        $this->migrateDown();
        $this->assertFalse(Schema::hasColumn('entitlements', 'assessment_case_id'));
        $this->assertDatabaseHas('entitlements', ['id' => $direct['dass'], 'test_type' => 'dass21']);
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_idempotent_rerun_rejects_counterfeit_sqlite_definition_without_delta(): void
    {
        $this->directGraph('counterfeit');
        $this->migrateUp();
        DB::unprepared('DROP INDEX entitlements_case_test_type_unique; CREATE UNIQUE INDEX entitlements_case_test_type_unique ON entitlements (assessment_case_id,test_type) WHERE 0');
        $before = $this->sqliteSchema();

        try {
            $this->migrateUp();
            $this->fail('Counterfeit SQLite enforcement must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial SQLite enforcement', $exception->getMessage());
        }

        $this->assertSame($before, $this->sqliteSchema());
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    /** @return array{branch:int,package:int,participant:int,case:int,order:int,generic:int,dass:int,session:int} */
    private function directGraph(string $suffix): array
    {
        $branch = $this->branch($suffix);
        $package = $this->package($suffix, ['dass21', 'ist']);
        $participant = $this->participant($suffix, $branch, $package, 'DIRECT_PUBLIC');
        $publicId = (string) Str::ulid();
        $case = $this->case($participant, $branch, $package, 'DIRECT_PUBLIC', $publicId);
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'method-'.$suffix, 'display_name' => $suffix, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant, 'assessment_case_id' => $case,
            'payment_method_id' => $method, 'status' => 'paid', 'amount' => 99000,
            'currency' => 'IDR', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dass = DB::table('entitlements')->insertGetId($this->entitlementRow($participant, $order, 'dass21'));
        $generic = DB::table('entitlements')->insertGetId($this->entitlementRow($participant, $order, 'ist'));
        $session = $this->boundSession($participant, $case, 'ist');

        return compact('branch', 'package', 'participant', 'case', 'order', 'generic', 'dass', 'session');
    }

    /** @return array{branch:int,participant:int,case:int,selection:int,generic:int,session:int} */
    private function legacyGraph(string $suffix): array
    {
        $branch = $this->branch($suffix);
        $participant = $this->participant($suffix, $branch, null, 'SELEKSI_BEASISWA_JEPANG');
        $case = $this->case($participant, $branch, null, 'LEGACY_SELECTION', (string) Str::ulid());
        $selection = DB::table('selection_participants')->insertGetId([
            'client_id' => 'client-'.$suffix, 'external_candidate_id' => 'candidate-'.$suffix,
            'selection_round_id' => 'round-'.$suffix, 'registration_id' => 'registration-'.$suffix,
            'participant_id' => $participant, 'assessment_case_id' => $case,
            'idempotency_key' => 'key-'.$suffix, 'request_hash' => hash('sha256', $suffix),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $generic = DB::table('entitlements')->insertGetId($this->entitlementRow($participant, null, 'papi'));
        $session = $this->boundSession($participant, $case, 'papi');

        return compact('branch', 'participant', 'case', 'selection', 'generic', 'session');
    }

    /** @param array{branch:int,participant:int,case:int,order:int,generic:int,session:int} $graph */
    private function insertDirectGrant(array $graph): void
    {
        DB::table('test_session_grants')->insert([
            'test_session_id' => $graph['session'], 'assessment_case_id' => $graph['case'],
            'participant_id' => $graph['participant'], 'organization_id' => $graph['branch'],
            'test_type' => 'ist', 'origin' => 'DIRECT_PUBLIC', 'grant_kind' => 'entitlement',
            'order_id' => $graph['order'], 'entitlement_id' => $graph['generic'], 'created_at' => now(),
        ]);
    }

    /** @param array{branch:int,participant:int,case:int,selection:int,generic:int,session:int} $graph */
    private function insertLegacyGrant(array $graph): void
    {
        DB::table('test_session_grants')->insert([
            'test_session_id' => $graph['session'], 'assessment_case_id' => $graph['case'],
            'participant_id' => $graph['participant'], 'organization_id' => $graph['branch'],
            'test_type' => 'papi', 'origin' => 'LEGACY_SELECTION', 'grant_kind' => 'entitlement',
            'selection_participant_id' => $graph['selection'], 'entitlement_id' => $graph['generic'],
            'created_at' => now(),
        ]);
    }

    private function boundSession(int $participant, int $case, string $testType): int
    {
        return DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => $testType, 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function branch(string $suffix): int
    {
        $key = strtoupper(substr(hash('sha256', $suffix), 0, 20));

        return DB::table('branches')->insertGetId([
            'code' => $key, 'name' => $suffix, 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => $suffix,
        ]);
    }

    /** @param list<string> $types */
    private function package(string $suffix, array $types): int
    {
        $package = DB::table('packages')->insertGetId([
            'code' => 'package-'.$suffix, 'name' => $suffix, 'amount' => 99000,
            'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($types as $sort => $type) {
            DB::table('package_items')->insert([
                'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $package;
    }

    private function participant(string $suffix, int $branch, ?int $package, string $source): int
    {
        return DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'manual',
            'package_id' => $package, 'source_system' => $source, 'full_name' => $suffix,
            'intended_field' => 'KAIGO', 'phone' => '620000000000',
        ]);
    }

    private function case(int $participant, int $branch, ?int $package, string $origin, string $publicId): int
    {
        return DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant, 'organization_id' => $branch,
            'package_id' => $package, 'origin' => $origin, 'intended_field_snapshot' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function entitlementRow(int $participant, ?int $order, string $type): array
    {
        return [
            'participant_id' => $participant, 'order_id' => $order, 'test_type' => $type,
            'status' => 'ready', 'ready_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Database invariant was not enforced.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return list<array<string,mixed>> */
    private function sqliteSchema(): array
    {
        return array_values(array_map(static fn (object $row): array => (array) $row, DB::select(
            "SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name",
        )));
    }

    private function migrateUp(): void
    {
        $migration = $this->migration();
        (new \ReflectionMethod($migration, 'up'))->invoke($migration);
    }

    private function migrateDown(): void
    {
        $migration = $this->migration();
        (new \ReflectionMethod($migration, 'down'))->invoke($migration);
    }

    private function migration(): Migration
    {
        $migration = require database_path('migrations/2026_09_10_000300_expand_generic_entitlement_case_identity.php');
        if (! $migration instanceof Migration) {
            throw new RuntimeException('Generic entitlement case migration did not return a migration.');
        }

        return $migration;
    }

    private function invokeMigration(string $filename, string $operation): void
    {
        $migration = require database_path('migrations/'.$filename);
        if (! $migration instanceof Migration || ! in_array($operation, ['up', 'down'], true)) {
            throw new RuntimeException('Migration boundary is unavailable.');
        }
        (new \ReflectionMethod($migration, $operation))->invoke($migration);
    }
}
