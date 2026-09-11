<?php

declare(strict_types=1);

namespace Tests\Feature\Retention;

use App\Actions\Retention\PurgeExpiredAuditLogs;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PurgeExpiredAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_service_purges_due_audits_across_tenants_in_bounded_batches_only(): void
    {
        $cutoff = CarbonImmutable::parse('2029-02-28T03:15:00.123456Z');
        CarbonImmutable::setTestNow($cutoff);
        [$firstBranch, $secondBranch] = $this->branches();

        $expiredIds = [
            $this->audit($firstBranch, $cutoff->subSecond()),
            $this->audit(null, $cutoff),
            $this->audit($secondBranch, $cutoff->subDay()),
        ];
        $futureIds = [
            $this->audit($firstBranch, $cutoff->addSecond()),
            $this->audit($secondBranch, $cutoff->addYear()),
        ];
        $outboxId = $this->expiredOutbox($cutoff->subYear());
        $deleteQueries = 0;
        $deletedBatches = [];

        DB::listen(static function (QueryExecuted $query) use (&$deleteQueries, &$deletedBatches): void {
            if (str_starts_with(strtolower($query->sql), 'delete')
                && str_contains(strtolower($query->sql), 'audit_logs')) {
                $deleteQueries++;
                $deletedBatches[] = array_map(
                    static fn (mixed $id): int => (int) $id,
                    array_slice($query->bindings, 0, -1),
                );
            }
        });

        $firstDeleted = app(RlsContextRunner::class)->runAsService(
            fn (): int => app(PurgeExpiredAuditLogs::class)->execute(2),
        );

        $this->assertSame(2, $firstDeleted);
        $this->assertSame(1, $deleteQueries);
        $this->assertSame([[$expiredIds[0], $expiredIds[1]]], $deletedBatches);
        $this->assertSame([$expiredIds[2]], DB::table('audit_logs')->whereIn('id', $expiredIds)->pluck('id')->all());
        $this->assertSame($futureIds, DB::table('audit_logs')->whereIn('id', $futureIds)->orderBy('id')->pluck('id')->all());
        $this->assertTrue(DB::table('outbox_messages')->where('id', $outboxId)->exists());

        $this->assertSame(
            1,
            app(RlsContextRunner::class)->runAsService(
                fn (): int => app(PurgeExpiredAuditLogs::class)->execute(2),
            ),
        );

        $this->assertSame(2, $deleteQueries);
        $this->assertSame([[$expiredIds[0], $expiredIds[1]], [$expiredIds[2]]], $deletedBatches);
        $this->assertSame([], DB::table('audit_logs')->whereIn('id', $expiredIds)->pluck('id')->all());
        $this->assertSame($futureIds, DB::table('audit_logs')->orderBy('id')->pluck('id')->all());
        $this->assertTrue(DB::table('outbox_messages')->where('id', $outboxId)->exists());

        $this->assertSame(
            0,
            app(RlsContextRunner::class)->runAsService(
                fn (): int => app(PurgeExpiredAuditLogs::class)->execute(2),
            ),
        );
    }

    #[DataProvider('invalidBatchSizes')]
    public function test_batch_size_must_be_strictly_positive_and_bounded(int $batchSize): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit retention batch size must be between 1 and 1000.');

        app(RlsContextRunner::class)->runAsService(
            fn (): int => app(PurgeExpiredAuditLogs::class)->execute($batchSize),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function invalidBatchSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above maximum' => [1001];
    }

    public function test_action_requires_an_active_service_context_and_never_elevates_itself(): void
    {
        $expired = $this->audit(null, CarbonImmutable::now('UTC')->subSecond());
        $action = app(PurgeExpiredAuditLogs::class);

        foreach ([
            'missing' => fn (): int => $action->execute(1),
            'tenant administrator' => fn (): int => app(RlsContextRunner::class)->run(
                new RlsContext('branch_admin', $this->branches()[0]),
                fn (): int => $action->execute(1),
            ),
        ] as $context => $call) {
            try {
                $call();
                $this->fail("The {$context} context must be rejected.");
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Audit retention requires an active service transaction.',
                    $exception->getMessage(),
                );
            }
        }

        $this->assertTrue(DB::table('audit_logs')->where('id', $expired)->exists());
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    public function test_delete_failure_rolls_back_the_entire_batch(): void
    {
        $cutoff = CarbonImmutable::parse('2028-01-01T00:00:00Z');
        CarbonImmutable::setTestNow($cutoff);
        $ids = [
            $this->audit(null, $cutoff->subSeconds(3)),
            $this->audit(null, $cutoff->subSeconds(2)),
            $this->audit(null, $cutoff->subSecond()),
        ];
        $deleteQueries = 0;

        DB::listen(static function (QueryExecuted $query) use (&$deleteQueries): void {
            if (! str_starts_with(strtolower($query->sql), 'delete')
                || ! str_contains(strtolower($query->sql), 'audit_logs')) {
                return;
            }

            $deleteQueries++;

            if ($deleteQueries === 1) {
                throw new RuntimeException('synthetic retention failure');
            }
        });

        try {
            app(RlsContextRunner::class)->runAsService(
                fn (): int => app(PurgeExpiredAuditLogs::class)->execute(3),
            );
            $this->fail('The synthetic failure must escape the service transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic retention failure', $exception->getMessage());
        }

        $this->assertSame($ids, DB::table('audit_logs')->orderBy('id')->pluck('id')->all());
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    /** @return array{int, int} */
    private function branches(): array
    {
        return [
            DB::table('branches')->insertGetId([
                'code' => 'RETENTION-A', 'name' => 'Retention A', 'ref_code' => 'RETENTION-A',
            ]),
            DB::table('branches')->insertGetId([
                'code' => 'RETENTION-B', 'name' => 'Retention B', 'ref_code' => 'RETENTION-B',
            ]),
        ];
    }

    private function audit(?int $branchId, CarbonImmutable $expiresAt): int
    {
        return DB::table('audit_logs')->insertGetId([
            'branch_id' => $branchId,
            'actor_type' => 'service',
            'actor_id' => null,
            'action' => 'retention.synthetic',
            'subject_type' => 'Synthetic',
            'subject_id' => null,
            'context' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR),
            'occurred_at' => $expiresAt->subYears(5),
            'expires_at' => $expiresAt,
        ]);
    }

    private function expiredOutbox(CarbonImmutable $expiresAt): int
    {
        return DB::table('outbox_messages')->insertGetId([
            'message_id' => (string) Str::ulid(),
            'deduplication_key' => hash('sha256', 'retention-fixture'),
            'topic' => 'retention.synthetic',
            'aggregate_type' => 'Synthetic',
            'aggregate_id' => 'synthetic',
            'payload' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR),
            'status' => 'processed',
            'attempts' => 1,
            'available_at' => $expiresAt->subDay(),
            'processed_at' => $expiresAt->subDay(),
            'expires_at' => $expiresAt,
            'last_error' => null,
            'created_at' => $expiresAt->subDay(),
            'updated_at' => $expiresAt->subDay(),
        ]);
    }
}
