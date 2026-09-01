<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Payments\ReserveAssessmentInvoiceReconciliationHints;
use App\Models\AssessmentBill;
use App\Security\RlsContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** PostgreSQL-authoritative SKIP LOCKED, process overlap, RLS-runtime, and rollback proof. */
final class AssessmentInvoiceReconciliationHintReservationTest extends TestCase
{
    /** @var list<int> */
    private array $messageIds = [];

    /** @var list<int> */
    private array $branchIds = [];

    /** @var array<string, mixed> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertFileExists('/.dockerenv');
        $runId = getenv('ORG_TEST_RUN_ID');
        $this->assertIsString($runId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $runId);
        $identity = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $this->previous = [
            'invoice_reconciliation_batch_size' => config('assessment_billing.invoice_reconciliation_batch_size'),
            'invoice_reconciliation_scan_limit' => config('assessment_billing.invoice_reconciliation_scan_limit'),
            'invoice_reconciliation_lease_seconds' => config('assessment_billing.invoice_reconciliation_lease_seconds'),
            'invoice_reconciliation_cooldown_seconds' => config('assessment_billing.invoice_reconciliation_cooldown_seconds'),
            'invoice_reconciliation_max_lookups' => config('assessment_billing.invoice_reconciliation_max_lookups'),
        ];
        config()->set('assessment_billing.invoice_reconciliation_batch_size', 25);
        config()->set('assessment_billing.invoice_reconciliation_scan_limit', 100);
        config()->set('assessment_billing.invoice_reconciliation_lease_seconds', 60);
        config()->set('assessment_billing.invoice_reconciliation_cooldown_seconds', 300);
        config()->set('assessment_billing.invoice_reconciliation_max_lookups', 12);
    }

    protected function tearDown(): void
    {
        try {
            app(RlsContextRunner::class)->runAsService(function (): void {
                DB::table('outbox_messages')->whereIn('id', $this->messageIds)->delete();
                DB::table('branches')->whereIn('id', $this->branchIds)->delete();
            });
            foreach ($this->previous as $key => $value) {
                config()->set('assessment_billing.'.$key, $value);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_processes_receive_disjoint_rows_with_bounded_totals(): void
    {
        for ($index = 0; $index < 6; $index++) {
            $this->insertHint();
        }

        $results = $this->runWorkers(2, 2, 2);

        $first = array_column($results[0], 'messageId');
        $second = array_column($results[1], 'messageId');
        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertSame([], array_values(array_intersect($first, $second)));
        $this->assertCount(4, array_unique([...$first, ...$second]));
        $this->assertSame(4, app(RlsContextRunner::class)->runAsService(fn (): int => DB::table('outbox_messages')
            ->whereIn('id', $this->messageIds)->whereNotNull('reconciliation_lease_token')->count()));
        $this->assertSame(0, app(RlsContextRunner::class)->runAsService(fn (): int => DB::table('outbox_messages')
            ->whereIn('id', $this->messageIds)->where('reconciliation_lookup_attempts', '<>', 0)->count()));
    }

    public function test_skip_locked_worker_finishes_while_organization_and_first_hint_are_locked(): void
    {
        $branch = $this->insertBranch();
        $locked = $this->insertHint();
        $free = $this->insertHint();
        DB::purge('pgsql');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Cannot create provisional reservation worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 8);
            try {
                DB::statement("SET lock_timeout = '3s'");
                DB::statement("SET statement_timeout = '5s'");
                fwrite($pair[1], "ready\n");
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Reservation barrier timed out.');
                }
                $started = microtime(true);
                $leases = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(2, 2);
                $result = ['elapsed' => microtime(true) - $started,
                    'messages' => array_map(fn ($lease): string => $lease->messageId, $leases)];
            } catch (Throwable $exception) {
                $result = ['error' => $exception->getMessage(), 'class' => $exception::class];
            }
            fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
            fclose($pair[1]);
            DB::disconnect('pgsql');
            exit(0);
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 8);
        try {
            $this->assertSame("ready\n", fgets($pair[0]));
            $result = app(RlsContextRunner::class)->runAsService(function () use ($branch, $locked, $pair): array {
                DB::table('branches')->where('id', $branch)->lockForUpdate()->first();
                DB::table('outbox_messages')->where('id', $locked)->lockForUpdate()->first();
                fwrite($pair[0], "go\n");
                $line = fgets($pair[0]);
                if (! is_string($line)) {
                    throw new RuntimeException('Worker waited on a lock that phase one must not acquire.');
                }

                return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            });
            $this->assertArrayNotHasKey('error', $result);
            $this->assertLessThan(3.0, $result['elapsed']);
            $this->assertSame([$this->messageId($free)], $result['messages']);
            $this->assertNull(app(RlsContextRunner::class)->runAsService(fn () => DB::table('outbox_messages')
                ->where('id', $locked)->value('reconciliation_lease_token')));
            $followup = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(1, 1);
            $this->assertSame([$this->messageId($locked)], array_map(fn ($lease): string => $lease->messageId, $followup));
        } finally {
            fclose($pair[0]);
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    public function test_postgres_skips_ineligible_rows_and_reclaims_expired_lease_without_counter(): void
    {
        config()->set('assessment_billing.invoice_reconciliation_max_lookups', 2);
        $expired = $this->insertHint([
            'reconciliation_lease_token' => '11111111-1111-4111-8111-111111111111',
            'reconciliation_lease_expires_at' => now()->subMinute(),
        ]);
        $oldToken = $this->token($expired);
        $active = $this->insertHint([
            'reconciliation_lease_token' => '22222222-2222-4222-8222-222222222222',
            'reconciliation_lease_expires_at' => now()->addMinute(),
        ]);
        $skipped = [
            $active,
            $this->insertHint(['reconciliation_next_at' => now()->addMinute()]),
            $this->insertHint(['reconciliation_lookup_attempts' => 2]),
            $this->insertHint(['status' => 'failed', 'last_error' => 'NONCANONICAL']),
            $this->insertHint(['aggregate_type' => 'synthetic']),
            $this->insertHint(['processed_at' => now()]),
        ];

        $leases = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(10, 10);

        $this->assertCount(1, $leases);
        $this->assertSame($this->messageId($expired), $leases[0]->messageId);
        $this->assertNotSame($oldToken, $leases[0]->leaseToken);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $this->token($active));
        $this->assertSame(0, app(RlsContextRunner::class)->runAsService(fn (): int => DB::table('outbox_messages')
            ->whereIn('id', $skipped)->whereNotNull('reconciliation_lease_token')
            ->where('id', '<>', $active)->count()));
        $this->assertSame(0, app(RlsContextRunner::class)->runAsService(fn (): int => DB::table('outbox_messages')
            ->whereIn('id', $this->messageIds)->where('reconciliation_lookup_attempts', '<>', 0)
            ->where('id', '<>', $this->messageIds[3])->count()));
    }

    public function test_postgres_exclusion_set_advances_to_later_due_rows(): void
    {
        $excluded = $this->insertHint();
        $first = $this->insertHint();
        $second = $this->insertHint();

        $leases = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(
            2,
            3,
            [$this->messageId($excluded)],
        );

        $this->assertSame(
            [$this->messageId($first), $this->messageId($second)],
            array_map(fn ($lease): string => $lease->messageId, $leases),
        );
        $this->assertNull($this->token($excluded));
        $this->assertNotNull($this->token($first));
        $this->assertNotNull($this->token($second));
    }

    public function test_postgres_failure_rolls_back_provisional_token(): void
    {
        $id = $this->insertHint();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'reconciliation_lease_token')) {
                $armed = false;
                throw new RuntimeException('synthetic-postgres-reservation-failure');
            }
        });
        try {
            app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(1, 1);
            $this->fail('Synthetic failure must roll back the lease.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic-postgres-reservation-failure', $exception->getMessage());
        }
        $this->assertFalse($armed);
        $this->assertNull($this->token($id));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    /** @return list<list<array<string, mixed>>> */
    private function runWorkers(int $workers, int $batch, int $scan): array
    {
        DB::purge('pgsql');
        $children = [];
        for ($index = 0; $index < $workers; $index++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false || ($pid = pcntl_fork()) === -1) {
                throw new RuntimeException('Cannot create concurrent reservation worker.');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                stream_set_timeout($pair[1], 10);
                try {
                    $identity = DB::selectOne('SELECT current_user AS name');
                    if ($identity->name !== 'psikotes_runtime') {
                        throw new RuntimeException('Child must use runtime role.');
                    }
                    fwrite($pair[1], "ready\n");
                    if (fgets($pair[1]) !== "go\n") {
                        throw new RuntimeException('Concurrent reservation barrier timed out.');
                    }
                    $leases = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute($batch, $scan);
                    $result = array_map(fn ($lease): array => ['messageId' => $lease->messageId,
                        'leaseToken' => $lease->leaseToken], $leases);
                } catch (Throwable $exception) {
                    $result = ['error' => $exception->getMessage(), 'class' => $exception::class];
                }
                fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                fclose($pair[1]);
                DB::disconnect('pgsql');
                exit(0);
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 10);
            $children[] = ['pid' => $pid, 'socket' => $pair[0]];
        }
        $results = [];
        try {
            foreach ($children as $child) {
                $this->assertSame("ready\n", fgets($child['socket']));
            }
            foreach ($children as $child) {
                fwrite($child['socket'], "go\n");
            }
            foreach ($children as $child) {
                $line = fgets($child['socket']);
                if (! is_string($line)) {
                    throw new RuntimeException('Concurrent reservation worker timed out.');
                }
                $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (isset($result['error'])) {
                    throw new RuntimeException(json_encode($result, JSON_THROW_ON_ERROR));
                }
                $results[] = $result;
            }
        } finally {
            foreach ($children as $child) {
                fclose($child['socket']);
                pcntl_waitpid($child['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $override */
    private function insertHint(array $override = []): int
    {
        $id = app(RlsContextRunner::class)->runAsService(function () use ($override): int {
            $messageId = (string) Str::ulid();
            $at = now();

            return DB::table('outbox_messages')->insertGetId([...[
                'message_id' => $messageId, 'deduplication_key' => hash('sha256', $messageId),
                'topic' => 'assessment.bill.invoice-issuance', 'aggregate_type' => AssessmentBill::class,
                'aggregate_id' => (string) random_int(1, PHP_INT_MAX), 'payload' => '{}', 'status' => 'processing',
                'attempts' => 1, 'available_at' => $at, 'processed_at' => null, 'expires_at' => $at->addYears(2),
                'last_error' => null, 'created_at' => $at, 'updated_at' => $at,
            ], ...$override]);
        });
        $this->messageIds[] = $id;

        return $id;
    }

    private function insertBranch(): int
    {
        $id = app(RlsContextRunner::class)->runAsService(function (): int {
            $key = (string) Str::ulid();

            return DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic',
                'organization_code' => $key, 'display_name' => 'Synthetic',
            ]);
        });
        $this->branchIds[] = $id;

        return $id;
    }

    private function token(int $id): ?string
    {
        $token = app(RlsContextRunner::class)->runAsService(fn () => DB::table('outbox_messages')
            ->where('id', $id)->value('reconciliation_lease_token'));
        if ($token !== null && ! is_string($token)) {
            throw new RuntimeException('Lease token has an invalid database type.');
        }

        return $token;
    }

    private function messageId(int $id): string
    {
        $messageId = app(RlsContextRunner::class)->runAsService(fn () => DB::table('outbox_messages')
            ->where('id', $id)->value('message_id'));
        if (! is_string($messageId)) {
            throw new RuntimeException('Synthetic hint is missing its message id.');
        }

        return $messageId;
    }
}
