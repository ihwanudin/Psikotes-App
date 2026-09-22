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
 * item-delivery reconciliation (2026-09-22). PostgreSQL evidence for
 * database/migrations/2026_09_22_030000_reconcile_item_content_variant_with_timed_segments_guard.php,
 * which is now the actual last word on guard_test_sessions_identity_revision()'s
 * live definition (see that migration's own docblock for the full
 * discovery/reasoning): (1) the trigger enforces item_content_variant
 * immutability again on the current, segment-aware function body -- the
 * real gap Lead's organization-postgres CI run on PR #128 found; (2) an
 * up->down->up cycle on THIS migration restores the exact live definition
 * (unlike #76's own migration, which is no longer the sole owner of this
 * function and so can no longer make that same exact-round-trip promise
 * in isolation -- see ItemContentVariantMigrationTest's own updated test).
 */
final class ReconcileItemContentVariantWithTimedSegmentsGuardMigrationTest extends TestCase
{
    private const MIGRATION_PATH = 'migrations/2026_09_22_030000_reconcile_item_content_variant_with_timed_segments_guard.php';

    public function test_item_content_variant_is_immutable_on_the_current_segment_aware_function(): void
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

    public function test_segment_state_mutability_still_works_after_this_migration_reinstalled_the_function(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::beginTransaction();
            try {
                $id = DB::table('test_sessions')->insertGetId($this->sessionRow(null));

                // A legal 'created' -> 'in_progress' transition may set
                // current_segment_index for the first time -- proves the
                // timed-segments half of the reconciled function still
                // works, not just the variant half. started_at/ends_at also
                // set here: an unrelated, earlier CHECK constraint
                // (test_sessions_lifecycle_check) requires them together
                // with status='in_progress', independent of this migration.
                $startedAt = now();
                DB::table('test_sessions')->where('id', $id)->update([
                    'status' => 'in_progress',
                    'started_at' => $startedAt,
                    'ends_at' => $startedAt->copy()->addSeconds(900),
                    'current_segment_index' => 0,
                    'current_segment_became_current_at' => $startedAt,
                ]);
                $this->assertSame(0, (int) DB::table('test_sessions')->where('id', $id)->value('current_segment_index'));

                // Regressing the segment index is still rejected.
                $this->assertSqlState('P0001', fn () => DB::table('test_sessions')
                    ->where('id', $id)->update(['current_segment_index' => -1]));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_down_then_up_restores_the_exact_live_definition(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement("SELECT set_config('app.role','service',true)");
                $reconciled = $this->functionSource('guard_test_sessions_identity_revision');
                $this->assertStringContainsString('item_content_variant', $reconciled);
                $this->assertStringContainsString('current_segment_index', $reconciled);

                $this->runMigration('down');
                $withoutVariant = $this->functionSource('guard_test_sessions_identity_revision');
                $this->assertStringNotContainsString('item_content_variant', $withoutVariant);
                $this->assertStringContainsString('current_segment_index', $withoutVariant);
                $this->assertNotSame($reconciled, $withoutVariant);

                $this->runMigration('up');
                $this->assertSame($reconciled, $this->functionSource('guard_test_sessions_identity_revision'));
            } finally {
                DB::rollBack();
            }
        });
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.reconcile_variant_segments_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('reconcile_variant_segments_owner');
        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            DB::purge('reconcile_variant_segments_owner');
            config()->set('database.connections.reconcile_variant_segments_owner', null);
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
            'code' => $key, 'ref_code' => $key, 'name' => 'Reconcile Variant Segments Synthetic',
            'organization_code' => $key, 'display_name' => 'Reconcile Variant Segments Synthetic',
        ]);

        return DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch,
            'referral_source' => 'default', 'full_name' => 'Reconcile Variant Segments Synthetic',
            'phone' => '620000000000',
        ]);
    }
}
