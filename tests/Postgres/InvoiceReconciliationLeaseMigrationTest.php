<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Models\AssessmentBill;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** PostgreSQL-authoritative constraint, index, RLS-runtime, and DDL lifecycle proof. */
final class InvoiceReconciliationLeaseMigrationTest extends TestCase
{
    public function test_runtime_schema_types_defaults_valid_states_and_partial_index_are_authoritative(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);

        $columns = DB::table('information_schema.columns')->where('table_schema', 'public')
            ->where('table_name', 'outbox_messages')->whereIn('column_name', [
                'reconciliation_lease_token', 'reconciliation_lease_expires_at',
                'reconciliation_next_at', 'reconciliation_lookup_attempts',
            ])->get()->keyBy('column_name');
        $this->assertSame('uuid', $columns['reconciliation_lease_token']->data_type);
        $this->assertSame('timestamp with time zone', $columns['reconciliation_lease_expires_at']->data_type);
        $this->assertSame('timestamp with time zone', $columns['reconciliation_next_at']->data_type);
        $this->assertSame('smallint', $columns['reconciliation_lookup_attempts']->data_type);
        $this->assertStringContainsString('0', $columns['reconciliation_lookup_attempts']->column_default);

        $index = DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', 'outbox_messages')
            ->where('indexname', 'outbox_invoice_reconciliation_discovery_idx')->value('indexdef');
        $this->assertIsString($index);
        $normalized = strtolower($index);
        foreach ([' where ', 'topic', 'assessment.bill.invoice-issuance', 'aggregate_type', 'app\\models\\assessmentbill',
            'attempts = 1', 'processed_at is null', 'status', 'processing', 'failed', 'last_error',
            'invoice_outcome_unknown'] as $clause) {
            $this->assertStringContainsString($clause, $normalized);
        }
        $this->assertStringNotContainsString('now()', $normalized);
        $this->assertStringNotContainsString('current_timestamp', $normalized);

        app(RlsContextRunner::class)->runAsService(function (): void {
            foreach ([['processing', null], ['failed', 'INVOICE_OUTCOME_UNKNOWN']] as [$status, $error]) {
                $id = $this->insertMessage('assessment.bill.invoice-issuance', $status, 1, $error);
                DB::table('outbox_messages')->where('id', $id)->update([
                    'reconciliation_lease_token' => (string) Str::uuid(),
                    'reconciliation_lease_expires_at' => now()->addMinute(),
                ]);
                $this->assertSame(0, DB::table('outbox_messages')->where('id', $id)->value('reconciliation_lookup_attempts'));
                DB::table('outbox_messages')->where('id', $id)->delete();
            }
            $legacy = $this->insertMessage();
            $this->assertSame(0, DB::table('outbox_messages')->where('id', $legacy)->value('reconciliation_lookup_attempts'));
            DB::table('outbox_messages')->where('id', $legacy)->delete();
        });
    }

    /**
     * @param  array{}|array{0: string, 1: string, 2: int, 3: string|null}  $identity
     * @param  array<string, mixed>  $metadata
     */
    #[DataProvider('invalidMetadata')]
    public function test_runtime_direct_sql_rejects_every_invalid_constraint_case(array $identity, array $metadata): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        app(RlsContextRunner::class)->runAsService(function () use ($identity, $metadata): void {
            $id = $this->insertMessage(...$identity);
            DB::table('outbox_messages')->where('id', $id)->update($metadata);
        });
    }

    /**
     * @return iterable<string, array{
     *     array{}|array{0: string, 1: string, 2: int, 3: string|null},
     *     array<string, mixed>
     * }>
     */
    public static function invalidMetadata(): iterable
    {
        $token = '11111111-1111-4111-8111-111111111111';
        $expiry = '2026-09-01 00:01:00+00:00';
        $active = ['reconciliation_lease_token' => $token, 'reconciliation_lease_expires_at' => $expiry];
        yield 'token without expiry' => [[], ['reconciliation_lease_token' => $token]];
        yield 'expiry without token' => [[], ['reconciliation_lease_expires_at' => $expiry]];
        yield 'negative counter' => [[], ['reconciliation_lookup_attempts' => -1]];
        yield 'counter over 100' => [[], ['reconciliation_lookup_attempts' => 101]];
        yield 'legacy token and expiry' => [[], $active];
        yield 'legacy cooldown' => [[], ['reconciliation_next_at' => $expiry]];
        yield 'legacy counter' => [[], ['reconciliation_lookup_attempts' => 1]];
        yield 'wrong aggregate' => [['assessment.bill.invoice-issuance', 'processing', 1, null],
            [...$active, 'aggregate_type' => 'synthetic']];
        yield 'attempts zero' => [['assessment.bill.invoice-issuance', 'processing', 0, null], $active];
        yield 'processed timestamp' => [['assessment.bill.invoice-issuance', 'processing', 1, null],
            [...$active, 'processed_at' => $expiry]];
        yield 'processing with error' => [['assessment.bill.invoice-issuance', 'processing', 1, null],
            [...$active, 'last_error' => 'NONCANONICAL']];
        yield 'failed wrong error' => [['assessment.bill.invoice-issuance', 'failed', 1, 'NONCANONICAL'], $active];
        yield 'pending state' => [['assessment.bill.invoice-issuance', 'pending', 1, null], $active];
    }

    public function test_owner_populated_roundtrip_and_refusal_are_atomic(): void
    {
        $this->assertFileExists('/.dockerenv');
        $runId = getenv('ORG_TEST_RUN_ID');
        $this->assertIsString($runId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $runId);
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        $this->assertSame('org-test-db', $config['host']);
        $this->assertSame('psikotes_organization_test', $config['database']);
        config()->set('database.connections.invoice_lease_ddl_test', [...$config, 'username' => 'org_test_owner']);
        $owner = DB::connection('invoice_lease_ddl_test');
        try {
            $owner->beginTransaction();
            DB::setDefaultConnection('invoice_lease_ddl_test');
            Schema::clearResolvedInstance('db.schema');
            $legacy = $this->insertMessage();
            $invoice = $this->insertMessage('assessment.bill.invoice-issuance', 'processing', 1);
            $base = DB::table('outbox_messages')->whereIn('id', [$legacy, $invoice])->orderBy('id')->get($this->baseColumns());
            $structure = $this->structure();

            $this->migrateDown();
            $this->assertFalse(Schema::hasColumn('outbox_messages', 'reconciliation_lease_token'));
            $this->assertEquals($base, DB::table('outbox_messages')->whereIn('id', [$legacy, $invoice])->orderBy('id')->get());
            $this->migrateUp();
            $this->assertEquals($structure, $this->structure());
            $this->assertEquals($base, DB::table('outbox_messages')->whereIn('id', [$legacy, $invoice])->orderBy('id')->get($this->baseColumns()));
            $this->assertSame(2, DB::table('outbox_messages')->whereIn('id', [$legacy, $invoice])
                ->whereNull('reconciliation_lease_token')->whereNull('reconciliation_lease_expires_at')
                ->whereNull('reconciliation_next_at')->where('reconciliation_lookup_attempts', 0)->count());

            DB::table('outbox_messages')->where('id', $invoice)->update([
                'reconciliation_lease_token' => (string) Str::uuid(),
                'reconciliation_lease_expires_at' => now()->addMinute(),
                'reconciliation_next_at' => now()->addMinutes(5),
                'reconciliation_lookup_attempts' => 1,
            ]);
            $before = DB::table('outbox_messages')->where('id', $invoice)->first();
            try {
                $this->migrateDown();
                $this->fail('Rollback discarded active reconciliation metadata.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('prevents rollback', $exception->getMessage());
            }
            $this->assertEquals($structure, $this->structure());
            $this->assertEquals($before, DB::table('outbox_messages')->where('id', $invoice)->first());
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('invoice_lease_ddl_test');
            config()->set('database.connections.invoice_lease_ddl_test', null);
        }
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
    }

    private function migrateUp(): void
    {
        $migration = require database_path('migrations/2026_09_01_000100_add_invoice_reconciliation_lease_to_outbox_messages.php');
        if (! is_object($migration) || ! method_exists($migration, 'up')) {
            throw new RuntimeException('Invoice reconciliation migration has no up method.');
        }
        $migration->up();
    }

    private function migrateDown(): void
    {
        $migration = require database_path('migrations/2026_09_01_000100_add_invoice_reconciliation_lease_to_outbox_messages.php');
        if (! is_object($migration) || ! method_exists($migration, 'down')) {
            throw new RuntimeException('Invoice reconciliation migration has no down method.');
        }
        $migration->down();
    }

    /** @return list<string> */
    private function baseColumns(): array
    {
        return ['id', 'message_id', 'deduplication_key', 'topic', 'aggregate_type', 'aggregate_id', 'payload',
            'status', 'attempts', 'available_at', 'processed_at', 'expires_at', 'last_error', 'created_at', 'updated_at'];
    }

    /** @return array{array<int, object>, array<int, object>, array<int, object>} */
    private function structure(): array
    {
        return [
            DB::select("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'outbox_messages' ORDER BY ordinal_position"),
            DB::select("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'outbox_messages'::regclass ORDER BY conname"),
            DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'outbox_messages' ORDER BY indexname"),
        ];
    }

    private function insertMessage(string $topic = 'participant.activation', string $status = 'pending', int $attempts = 0,
        ?string $lastError = null): int
    {
        return DB::table('outbox_messages')->insertGetId([
            'message_id' => (string) Str::ulid(), 'topic' => $topic,
            'aggregate_type' => $topic === 'assessment.bill.invoice-issuance' ? AssessmentBill::class : 'synthetic',
            'aggregate_id' => '1', 'payload' => '{}', 'status' => $status, 'attempts' => $attempts,
            'available_at' => now(), 'expires_at' => now()->addYears(2), 'last_error' => $lastError,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
