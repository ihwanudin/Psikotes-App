<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F2 item-delivery Stage 2 continuation (2026-09-21). PostgreSQL evidence
 * for database/migrations/2026_09_21_000200_add_item_content_variant_to_test_sessions.php,
 * per Lead's requirement (same as #57): (1) the CHECK constraint accepts
 * null/valid values and rejects malformed ones, (2) the column is
 * immutable after insert (extends the existing
 * guard_test_sessions_identity_revision trigger, not a parallel guard),
 * (3) down() aborts with a clear message when populated rows would be
 * discarded, and (4) an up->down->up cycle restores the exact original
 * trigger definition.
 */
final class ItemContentVariantMigrationTest extends TestCase
{
    private const MIGRATION_PATH = 'migrations/2026_09_21_000200_add_item_content_variant_to_test_sessions.php';

    public function test_the_check_constraint_accepts_null_and_valid_values_and_rejects_malformed_ones(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::beginTransaction();
            try {
                DB::table('test_sessions')->insert($this->sessionRow(null));
                DB::table('test_sessions')->insert($this->sessionRow('male'));
                DB::table('test_sessions')->insert($this->sessionRow('female'));

                $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert($this->sessionRow('')));
                $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert($this->sessionRow(' male')));
                $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert($this->sessionRow("male\n")));
                // 33 chars is rejected by the varchar(32) column itself
                // (22001, "string data right truncation") before the CHECK
                // constraint's own length clause is ever evaluated -- the
                // CHECK's upper bound is a second, redundant-by-design line
                // of defense, not the only one.
                $this->assertSqlState('22001', fn () => DB::table('test_sessions')->insert($this->sessionRow(str_repeat('a', 33))));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_the_column_is_immutable_after_insert(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::beginTransaction();
            try {
                $id = DB::table('test_sessions')->insertGetId($this->sessionRow('male'));

                $this->assertSqlState('P0001', fn () => DB::table('test_sessions')
                    ->where('id', $id)->update(['item_content_variant' => 'female']));
                $this->assertSqlState('P0001', fn () => DB::table('test_sessions')
                    ->where('id', $id)->update(['item_content_variant' => null]));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_migration_aborts_with_a_clear_message_when_populated_rows_would_be_discarded_by_rollback(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement("SELECT set_config('app.role','service',true)");
                DB::table('test_sessions')->insertGetId($this->sessionRow('male'));

                try {
                    $this->runMigration('down');
                    $this->fail('The migration must abort when a populated item_content_variant value exists.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('populated item_content_variant values prevent rollback', $exception->getMessage());
                }

                // The abort must leave the column and its guard intact, not half-dropped.
                $this->assertStringContainsString('item_content_variant', $this->functionSource('guard_test_sessions_identity_revision'));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_down_then_up_restores_the_exact_original_trigger_definition(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement("SELECT set_config('app.role','service',true)");
                $withVariant = $this->functionSource('guard_test_sessions_identity_revision');
                $this->assertStringContainsString('item_content_variant', $withVariant);

                $this->runMigration('down');
                $withoutColumn = $this->functionSource('guard_test_sessions_identity_revision');
                $this->assertStringNotContainsString('item_content_variant', $withoutColumn);
                $this->assertNotSame($withVariant, $withoutColumn);

                $this->runMigration('up');
                $this->assertSame($withVariant, $this->functionSource('guard_test_sessions_identity_revision'));
            } finally {
                DB::rollBack();
            }
        });
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.item_content_variant_migration_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('item_content_variant_migration_owner');
        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            DB::purge('item_content_variant_migration_owner');
            config()->set('database.connections.item_content_variant_migration_owner', null);
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
            'SELECT pg_get_functiondef(proc.oid) AS def FROM pg_proc proc
                WHERE proc.proname = ? AND pg_function_is_visible(proc.oid)',
            [$name],
        );
        if ($row === null) {
            throw new RuntimeException("Function {$name} was not found.");
        }

        return (string) $row->def;
    }

    /**
     * A failed statement aborts the whole enclosing PostgreSQL transaction
     * until a rollback happens. Each probe runs inside its own nested
     * DB::transaction() -- a SAVEPOINT -- so catching the expected error
     * also rolls back just that savepoint, leaving the outer transaction
     * usable for the next assertion.
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
    private function sessionRow(?string $itemContentVariant): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'participant_id' => $this->participant(),
            'test_type' => 'rmib',
            'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 900,
            'status' => 'created',
            'answers_revision' => 0,
            'item_content_variant' => $itemContentVariant,
        ];
    }

    private function participant(): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Item Content Variant Migration Synthetic',
            'organization_code' => $key, 'display_name' => 'Item Content Variant Migration Synthetic',
        ]);

        return DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch,
            'referral_source' => 'default', 'full_name' => 'Item Content Variant Migration Synthetic',
            'phone' => '620000000000',
        ]);
    }
}
