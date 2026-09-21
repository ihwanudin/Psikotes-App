<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F2 (2026-09-21). PostgreSQL evidence for
 * database/migrations/2026_09_21_000100_fix_kraepelin_randomization_mode.php,
 * per Lead's four review requirements: (1) the offending-row guard aborts
 * rather than silently rewriting data, (2) down() restores the old trigger
 * byte-exactly and an up->down->up cycle works cleanly, (3) the new
 * contract accepts fixed+null-seed Kraepelin rows and rejects seeded/
 * non-null-seed rows in both tables, and (4) the ist/papi/rmib branch is
 * unchanged.
 *
 * Data-only tests run under the ordinary psikotes_runtime role elevated to
 * 'service' via RlsContextRunner (same as every other session/catalog
 * fixture in this suite). The two tests that call this migration's own
 * up()/down() run under the real org_test_owner role instead
 * (CREATE OR REPLACE FUNCTION / DROP TRIGGER require object-owner
 * privileges RLS elevation does not grant), mirroring
 * TestSessionDefinitionSnapshotSecurityTest::asOwner().
 */
final class KraepelinRandomizationModeMigrationTest extends TestCase
{
    private const MIGRATION_PATH = 'migrations/2026_09_21_000100_fix_kraepelin_randomization_mode.php';

    public function test_catalog_accepts_fixed_null_seed_kraepelin_and_rejects_seeded_mode(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::beginTransaction();
            try {
                DB::table('assessment_session_definitions')->insert(
                    $this->catalogRow($this->kraepelinTemplate()),
                );

                $seededMode = $this->kraepelinTemplate();
                $seededMode['randomization'] = 'seeded';
                $this->assertSqlState('23514', fn () => DB::table('assessment_session_definitions')->insert(
                    $this->catalogRow($seededMode),
                ));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_snapshot_accepts_fixed_null_seed_kraepelin_and_rejects_seeded_or_non_null_seed(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::beginTransaction();
            try {
                DB::table('test_sessions')->insert(
                    $this->sessionRow('kraepelin', 750) + $this->snapshotRow($this->kraepelinTemplate()),
                );

                $seededMode = $this->kraepelinTemplate();
                $seededMode['randomization'] = 'seeded';
                $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert(
                    $this->sessionRow('kraepelin', 750) + $this->snapshotRow($seededMode),
                ));

                $nonNullSeed = $this->kraepelinTemplate();
                $nonNullSeed['seed'] = 'synthetic-seed';
                $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert(
                    $this->sessionRow('kraepelin', 750) + $this->snapshotRow($nonNullSeed),
                ));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_ist_papi_rmib_branch_is_unchanged_in_both_tables(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::beginTransaction();
            try {
                foreach (['ist', 'papi', 'rmib'] as $instrument) {
                    $template = $this->fixedTemplate($instrument);
                    DB::table('assessment_session_definitions')->insert($this->catalogRow($template));
                    DB::table('test_sessions')->insert(
                        $this->sessionRow($instrument, 60) + $this->snapshotRow($template),
                    );

                    $seeded = $template;
                    $seeded['randomization'] = 'seeded';
                    $this->assertSqlState('23514', fn () => DB::table('assessment_session_definitions')->insert(
                        $this->catalogRow($seeded),
                    ));
                }
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_migration_aborts_when_an_offending_row_exists_instead_of_rewriting_it(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement("SELECT set_config('app.role','service',true)");
                $this->runMigration('down');

                // Under the restored old ('seeded') contract, this row is valid.
                DB::table('assessment_session_definitions')->insert(
                    $this->catalogRow($this->legacySeededTemplate()),
                );

                try {
                    $this->runMigration('up');
                    $this->fail('The migration must abort when an old-contract Kraepelin row exists.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('aborted', $exception->getMessage());
                    $this->assertStringContainsString('1 assessment_session_definitions', $exception->getMessage());
                }

                // The abort must leave the old trigger in place, not a half-applied new one.
                $this->assertStringContainsString("'seeded'", $this->functionSource('guard_assessment_session_definitions'));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_down_then_up_restores_the_exact_original_trigger_definitions(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement("SELECT set_config('app.role','service',true)");
                $fixedCatalog = $this->functionSource('guard_assessment_session_definitions');
                $fixedSnapshot = $this->functionSource('guard_test_session_definition_snapshot');
                $this->assertStringContainsString("'fixed'", $fixedCatalog);
                $this->assertStringContainsString("'fixed'", $fixedSnapshot);

                $this->runMigration('down');
                $seededCatalog = $this->functionSource('guard_assessment_session_definitions');
                $seededSnapshot = $this->functionSource('guard_test_session_definition_snapshot');
                $this->assertStringContainsString("'seeded'", $seededCatalog);
                $this->assertStringContainsString("'seeded'", $seededSnapshot);
                $this->assertNotSame($fixedCatalog, $seededCatalog);
                $this->assertNotSame($fixedSnapshot, $seededSnapshot);

                $this->runMigration('up');
                $this->assertSame($fixedCatalog, $this->functionSource('guard_assessment_session_definitions'));
                $this->assertSame($fixedSnapshot, $this->functionSource('guard_test_session_definition_snapshot'));
            } finally {
                DB::rollBack();
            }
        });
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.kraepelin_migration_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('kraepelin_migration_owner');
        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            DB::purge('kraepelin_migration_owner');
            config()->set('database.connections.kraepelin_migration_owner', null);
        }
    }

    private function runMigration(string $direction): void
    {
        $migration = require database_path(self::MIGRATION_PATH);
        if (! in_array($direction, ['up', 'down'], true) || ! method_exists($migration, $direction)) {
            throw new RuntimeException("Migration operation {$direction} is unavailable.");
        }
        $migration->$direction();
    }

    private function functionSource(string $name): string
    {
        $row = DB::selectOne(
            "SELECT pg_get_functiondef(proc.oid) AS def FROM pg_proc proc
                JOIN pg_namespace namespace ON namespace.oid = proc.pronamespace
                WHERE proc.proname = ? AND namespace.nspname = 'app_private'",
            [$name],
        );
        if ($row === null) {
            throw new RuntimeException("Function app_private.{$name} was not found.");
        }

        return (string) $row->def;
    }

    /**
     * A failed statement aborts the whole enclosing PostgreSQL transaction
     * (SQLSTATE 25P02 for anything after it) until a rollback happens. Each
     * probe here runs inside its own nested DB::transaction() -- a SAVEPOINT
     * -- so catching the expected error also rolls back just that savepoint
     * and leaves the outer transaction usable for the next assertion.
     */
    private function assertSqlState(string $sqlState, callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail("Expected SQLSTATE {$sqlState}.");
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->getCode());
        }
    }

    /** @return array<string, mixed> */
    private function kraepelinTemplate(): array
    {
        $source = [
            'instrument' => 'kraepelin', 'version' => 'synthetic-migration-test-v1',
            'provenance' => 'kraepelin-randomization-mode-migration-test',
            'total_duration_seconds' => 750,
            'subtests' => [['code' => 'SYNTHETIC', 'duration_seconds' => 750, 'item_count' => 1350]],
            'randomization' => 'fixed', 'seed' => null,
            'generator' => [
                'algorithm' => 'synthetic-generator', 'version' => 'synthetic-v1', 'columns' => 50,
                'seconds_per_column' => 15, 'numbers_per_column' => 28, 'answer_slots_per_column' => 27,
            ],
        ];

        return [...$source, 'checksum' => SessionDefinition::checksumFor($source)];
    }

    /** @return array<string, mixed> */
    private function legacySeededTemplate(): array
    {
        $template = $this->kraepelinTemplate();
        $template['randomization'] = 'seeded';
        unset($template['checksum']);

        return [...$template, 'checksum' => SessionDefinition::checksumFor($template)];
    }

    /** @return array<string, mixed> */
    private function fixedTemplate(string $instrument): array
    {
        $source = [
            'instrument' => $instrument, 'version' => 'synthetic-migration-test-v1',
            'provenance' => 'kraepelin-randomization-mode-migration-test',
            'total_duration_seconds' => 60,
            'subtests' => [['code' => 'SYNTHETIC', 'duration_seconds' => 60, 'item_count' => 1]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];

        return [...$source, 'checksum' => SessionDefinition::checksumFor($source)];
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function catalogRow(array $template): array
    {
        $payload = $template;
        unset($payload['checksum']);

        return [
            'instrument' => $template['instrument'],
            'version' => $template['version'],
            'provenance' => $template['provenance'],
            'template_checksum' => $template['checksum'],
            'template_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'is_active' => true,
            'activated_at' => now(),
            'deactivated_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function snapshotRow(array $template): array
    {
        return [
            'session_definition_version' => $template['version'],
            'session_definition_provenance' => $template['provenance'],
            'session_definition_checksum' => $template['checksum'],
            'session_definition_payload' => json_encode($template, JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string, mixed> */
    private function sessionRow(string $testType, int $durationSeconds): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'participant_id' => $this->participant(),
            'test_type' => $testType,
            'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $durationSeconds,
            'status' => 'created',
            'answers_revision' => 0,
        ];
    }

    private function participant(): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Kraepelin Migration Synthetic',
            'organization_code' => $key, 'display_name' => 'Kraepelin Migration Synthetic',
        ]);

        return DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch,
            'referral_source' => 'default', 'full_name' => 'Kraepelin Migration Synthetic',
            'phone' => '620000000000',
        ]);
    }
}
