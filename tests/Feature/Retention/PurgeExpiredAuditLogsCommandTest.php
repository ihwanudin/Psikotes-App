<?php

declare(strict_types=1);

namespace Tests\Feature\Retention;

use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

final class PurgeExpiredAuditLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('disabledConfigurations')]
    public function test_command_fails_closed_without_an_exact_true_configuration(
        mixed $configuration,
        string $reasonCode,
    ): void {
        CarbonImmutable::setTestNow('2029-03-01T00:00:00Z');
        $expiredId = $this->audit('private-disabled-context', CarbonImmutable::now('UTC')->subSecond());
        config()->set('retention', $configuration);
        $logger = $this->spyLogger();

        $exitCode = Artisan::call('retention:purge-expired-audits');

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertTrue(DB::table('audit_logs')->where('id', $expiredId)->exists());
        $this->assertSame(1, DB::table('audit_logs')->count());
        $this->assertSame('Audit retention purge configuration or limit is invalid.'.PHP_EOL, Artisan::output());
        $logger->shouldHaveReceived('warning')->with(
            'Audit retention purge invocation rejected.',
            ['reasonCode' => $reasonCode],
        )->once();
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function disabledConfigurations(): iterable
    {
        yield 'missing retention configuration' => [null, 'AUDIT_RETENTION_DISABLED'];
        yield 'explicitly disabled' => [['audit_purge_enabled' => false], 'AUDIT_RETENTION_DISABLED'];
        yield 'string true is malformed' => [['audit_purge_enabled' => 'true'], 'AUDIT_RETENTION_CONFIGURATION_INVALID'];
        yield 'integer one is malformed' => [['audit_purge_enabled' => 1], 'AUDIT_RETENTION_CONFIGURATION_INVALID'];
    }

    #[DataProvider('invalidLimits')]
    public function test_command_rejects_noncanonical_or_out_of_range_limits(int|string $limit): void
    {
        CarbonImmutable::setTestNow('2029-03-01T00:00:00Z');
        $expiredId = $this->audit('private-limit-context', CarbonImmutable::now('UTC')->subSecond());
        config()->set('retention.audit_purge_enabled', true);
        $logger = $this->spyLogger();

        $exitCode = Artisan::call('retention:purge-expired-audits', ['--limit' => $limit]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertTrue(DB::table('audit_logs')->where('id', $expiredId)->exists());
        $logger->shouldHaveReceived('warning')->with(
            'Audit retention purge invocation rejected.',
            ['reasonCode' => 'AUDIT_RETENTION_LIMIT_INVALID'],
        )->once();
    }

    /** @return iterable<string, array{int|string}> */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above maximum' => [1001];
        yield 'decimal' => ['1.5'];
        yield 'scientific notation' => ['1e2'];
        yield 'leading zero' => ['01'];
        yield 'leading whitespace' => [' 1'];
        yield 'explicit plus sign' => ['+1'];
    }

    public function test_enabled_command_purges_exactly_one_bounded_batch_and_logs_only_safe_counts(): void
    {
        $cutoff = CarbonImmutable::parse('2029-03-01T00:00:00Z');
        CarbonImmutable::setTestNow($cutoff);
        $expiredIds = [
            $this->audit('private-first-context', $cutoff->subSeconds(3)),
            $this->audit('private-second-context', $cutoff->subSeconds(2)),
            $this->audit('private-third-context', $cutoff->subSecond()),
        ];
        $futureId = $this->audit('private-future-context', $cutoff->addSecond());
        config()->set('retention.audit_purge_enabled', true);
        $logger = $this->spyLogger();
        $transactionLevel = DB::transactionLevel();

        $exitCode = Artisan::call('retention:purge-expired-audits', ['--limit' => '2']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame('Purged 2 expired audit log(s).'.PHP_EOL, Artisan::output());
        $this->assertSame(
            [$expiredIds[2], $futureId],
            DB::table('audit_logs')->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(2, DB::table('audit_logs')->count(), 'The command must not create a feedback audit row.');
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame($transactionLevel, DB::transactionLevel());
        $logger->shouldHaveReceived('info')->with(
            'Audit retention purge invocation started.',
            ['batchLimit' => 2],
        )->once();
        $logger->shouldHaveReceived('info')->with(
            'Audit retention purge invocation completed.',
            ['batchLimit' => 2, 'deletedCount' => 2, 'batchSaturated' => true],
        )->once();
    }

    public function test_enabled_command_succeeds_when_no_audit_is_due(): void
    {
        CarbonImmutable::setTestNow('2029-03-01T00:00:00Z');
        $futureId = $this->audit('private-future-context', CarbonImmutable::now('UTC')->addSecond());
        config()->set('retention.audit_purge_enabled', true);
        $logger = $this->spyLogger();

        $exitCode = Artisan::call('retention:purge-expired-audits', ['--limit' => 10]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame('Purged 0 expired audit log(s).'.PHP_EOL, Artisan::output());
        $this->assertSame([$futureId], DB::table('audit_logs')->pluck('id')->all());
        $logger->shouldHaveReceived('info')->with(
            'Audit retention purge invocation completed.',
            ['batchLimit' => 10, 'deletedCount' => 0, 'batchSaturated' => false],
        )->once();
    }

    public function test_execution_failure_rolls_back_restores_context_and_reports_no_exception_detail(): void
    {
        $cutoff = CarbonImmutable::parse('2029-03-01T00:00:00Z');
        CarbonImmutable::setTestNow($cutoff);
        $ids = [
            $this->audit('private-rollback-context-a', $cutoff->subSeconds(2)),
            $this->audit('private-rollback-context-b', $cutoff->subSecond()),
        ];
        config()->set('retention.audit_purge_enabled', true);
        $logger = $this->spyLogger();
        $deleteQueries = 0;
        $transactionLevel = DB::transactionLevel();

        DB::listen(static function (QueryExecuted $query) use (&$deleteQueries): void {
            if (! str_starts_with(strtolower($query->sql), 'delete')
                || ! str_contains(strtolower($query->sql), 'audit_logs')) {
                return;
            }

            $deleteQueries++;

            if ($deleteQueries === 1) {
                throw new RuntimeException('private SQL and tenant detail');
            }
        });

        $exitCode = Artisan::call('retention:purge-expired-audits', ['--limit' => 2]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(
            'Audit retention purge failed. Inspect application logs before retrying.'.PHP_EOL,
            Artisan::output(),
        );
        $this->assertStringNotContainsString('private', Artisan::output());
        $this->assertStringNotContainsString('SQL', Artisan::output());
        $this->assertSame($ids, DB::table('audit_logs')->orderBy('id')->pluck('id')->all());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame($transactionLevel, DB::transactionLevel());
        $logger->shouldHaveReceived('error')->with(
            'Audit retention purge invocation failed.',
            ['reasonCode' => 'AUDIT_RETENTION_EXECUTION_FAILED', 'batchLimit' => 2],
        )->once();
    }

    private function audit(string $context, CarbonImmutable $expiresAt): int
    {
        return DB::table('audit_logs')->insertGetId([
            'branch_id' => null,
            'actor_type' => 'service',
            'actor_id' => null,
            'action' => 'retention.synthetic',
            'subject_type' => 'Synthetic',
            'subject_id' => null,
            'context' => json_encode(['fixture' => $context], JSON_THROW_ON_ERROR),
            'occurred_at' => $expiresAt->subYears(5),
            'expires_at' => $expiresAt,
        ]);
    }

    private function spyLogger(): MockInterface
    {
        $logger = Mockery::spy(LoggerInterface::class);
        Log::swap($logger);

        return $logger;
    }
}
