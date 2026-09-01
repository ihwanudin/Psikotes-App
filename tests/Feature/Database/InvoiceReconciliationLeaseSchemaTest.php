<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class InvoiceReconciliationLeaseSchemaTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
    }

    public function test_columns_defaults_model_casts_index_and_inactive_config_are_portable(): void
    {
        $this->assertTrue(Schema::hasColumns('outbox_messages', [
            'reconciliation_lease_token', 'reconciliation_lease_expires_at',
            'reconciliation_next_at', 'reconciliation_lookup_attempts',
        ]));
        $id = $this->insertMessage();
        $row = DB::table('outbox_messages')->where('id', $id);
        $this->assertNull($row->value('reconciliation_lease_token'));
        $this->assertNull($row->value('reconciliation_lease_expires_at'));
        $this->assertNull($row->value('reconciliation_next_at'));
        $this->assertSame(0, $row->value('reconciliation_lookup_attempts'));

        $token = (string) Str::uuid();
        DB::table('outbox_messages')->where('id', $id)->update([
            'reconciliation_lease_token' => $token,
            'reconciliation_lease_expires_at' => '2026-09-01 00:01:00+00:00',
            'reconciliation_next_at' => '2026-09-01 00:05:00+00:00',
            'reconciliation_lookup_attempts' => 12,
        ]);
        $model = OutboxMessage::query()->findOrFail($id);
        $this->assertSame($token, $model->reconciliation_lease_token);
        $this->assertInstanceOf(CarbonImmutable::class, $model->reconciliation_lease_expires_at);
        $this->assertInstanceOf(CarbonImmutable::class, $model->reconciliation_next_at);
        $this->assertSame(12, $model->reconciliation_lookup_attempts);
        $this->assertContains('outbox_invoice_reconciliation_discovery_idx',
            array_column(Schema::getIndexes('outbox_messages'), 'name'));

        $this->assertSame(25, config('assessment_billing.invoice_reconciliation_batch_size'));
        $this->assertSame(100, config('assessment_billing.invoice_reconciliation_scan_limit'));
        $this->assertSame(60, config('assessment_billing.invoice_reconciliation_lease_seconds'));
        $this->assertSame(300, config('assessment_billing.invoice_reconciliation_cooldown_seconds'));
        $this->assertSame(12, config('assessment_billing.invoice_reconciliation_max_lookups'));
    }

    public function test_populated_legacy_and_invoice_rows_survive_down_up_roundtrip(): void
    {
        $legacy = $this->insertMessage('participant.activation', 'pending', 0);
        $invoice = $this->insertMessage('assessment.bill.invoice-issuance', 'processing', 1);
        $before = DB::table('outbox_messages')->whereIn('id', [$legacy, $invoice])->orderBy('id')->get([
            'id', 'message_id', 'deduplication_key', 'topic', 'aggregate_type', 'aggregate_id', 'payload', 'status', 'attempts',
            'available_at', 'processed_at', 'expires_at', 'last_error', 'created_at', 'updated_at',
        ]);

        $this->migrateDown();
        $this->assertFalse(Schema::hasColumn('outbox_messages', 'reconciliation_lease_token'));
        $this->assertEquals($before, DB::table('outbox_messages')->whereIn('id', [$legacy, $invoice])->orderBy('id')->get());
        $this->migrateUp();

        $this->assertEquals($before, DB::table('outbox_messages')->whereIn('id', [$legacy, $invoice])->orderBy('id')->get([
            'id', 'message_id', 'deduplication_key', 'topic', 'aggregate_type', 'aggregate_id', 'payload', 'status', 'attempts',
            'available_at', 'processed_at', 'expires_at', 'last_error', 'created_at', 'updated_at',
        ]));
        $this->assertSame(2, DB::table('outbox_messages')->whereIn('id', [$legacy, $invoice])
            ->whereNull('reconciliation_lease_token')->whereNull('reconciliation_lease_expires_at')
            ->whereNull('reconciliation_next_at')->where('reconciliation_lookup_attempts', 0)->count());
    }

    #[DataProvider('activeMetadata')]
    public function test_down_refuses_each_nondefault_metadata_without_partial_schema(string $column, mixed $value): void
    {
        $id = $this->insertMessage('assessment.bill.invoice-issuance', 'processing', 1);
        DB::table('outbox_messages')->where('id', $id)->update([$column => $value]);
        $schema = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');
        $before = DB::table('outbox_messages')->find($id);
        try {
            $this->migrateDown();
            $this->fail('Rollback must not discard reconciliation metadata.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Invoice reconciliation metadata prevents rollback; drain leases and cooldown state explicitly.', $exception->getMessage());
        }
        $this->assertEquals($schema, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
        $this->assertEquals($before, DB::table('outbox_messages')->find($id));
        $this->assertTrue(Schema::hasColumns('outbox_messages', [
            'reconciliation_lease_token', 'reconciliation_lease_expires_at',
            'reconciliation_next_at', 'reconciliation_lookup_attempts',
        ]));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function activeMetadata(): iterable
    {
        yield 'token' => ['reconciliation_lease_token', '11111111-1111-4111-8111-111111111111'];
        yield 'expiry' => ['reconciliation_lease_expires_at', '2026-09-01 00:01:00+00:00'];
        yield 'cooldown' => ['reconciliation_next_at', '2026-09-01 00:05:00+00:00'];
        yield 'counter' => ['reconciliation_lookup_attempts', 1];
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

    private function insertMessage(string $topic = 'participant.activation', string $status = 'pending', int $attempts = 0): int
    {
        return DB::table('outbox_messages')->insertGetId([
            'message_id' => (string) Str::ulid(), 'topic' => $topic,
            'aggregate_type' => $topic === 'assessment.bill.invoice-issuance' ? 'App\\Models\\AssessmentBill' : 'synthetic',
            'aggregate_id' => '1', 'payload' => '{}', 'status' => $status, 'attempts' => $attempts,
            'available_at' => now(), 'expires_at' => now()->addYears(2), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
