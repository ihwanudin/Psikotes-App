<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Retention\PurgeExpiredAuditLogs;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PurgeExpiredAuditLogsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();

        parent::tearDown();
    }

    public function test_runtime_service_purges_expired_audits_across_tenants_under_forced_rls(): void
    {
        $cutoff = CarbonImmutable::parse('2029-02-28T03:15:00Z');
        CarbonImmutable::setTestNow($cutoff);
        $runner = app(RlsContextRunner::class);

        // The PostgreSQL suite shares one disposable database. Some preceding
        // fixtures intentionally commit audit rows, so isolate this test's
        // global retention queue inside the outer transaction. tearDown()
        // rolls this deletion back together with this test's own fixtures.
        $runner->runAsService(static function (): void {
            DB::table('audit_logs')->delete();
        });

        [$branches, $expired, $future] = $runner->runAsService(function () use ($cutoff): array {
            $branches = [
                $this->branch('RETENTION-PG-A'),
                $this->branch('RETENTION-PG-B'),
            ];

            return [
                $branches,
                [
                    $this->audit($branches[0], $cutoff),
                    $this->audit($branches[1], $cutoff->subSecond()),
                ],
                [
                    $this->audit($branches[0], $cutoff->addSecond()),
                    $this->audit(null, $cutoff->addYear()),
                ],
            ];
        });

        $this->assertSame(
            1,
            $runner->runAsService(fn (): int => app(PurgeExpiredAuditLogs::class)->execute(1)),
        );
        $runner->runAsService(function () use ($expired): void {
            $this->assertSame([$expired[1]], DB::table('audit_logs')->whereIn('id', $expired)->pluck('id')->all());
        });
        $this->assertSame(
            1,
            $runner->runAsService(fn (): int => app(PurgeExpiredAuditLogs::class)->execute(1)),
        );

        $runner->runAsService(function () use ($expired, $future): void {
            $this->assertSame([], DB::table('audit_logs')->whereIn('id', $expired)->pluck('id')->all());
            $this->assertSame($future, DB::table('audit_logs')->orderBy('id')->pluck('id')->all());
        });

        try {
            $runner->run(
                new RlsContext('branch_admin', $branches[0]),
                fn (): int => app(PurgeExpiredAuditLogs::class)->execute(1),
            );
            $this->fail('Tenant context must not run cross-tenant retention.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Audit retention requires an active service transaction.',
                $exception->getMessage(),
            );
        }

        $security = DB::selectOne(
            "SELECT current_user AS current_user, relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = 'audit_logs'::regclass",
        );
        $this->assertSame('psikotes_runtime', $security->current_user);
        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
        $this->assertNull($runner->current());
    }

    private function branch(string $code): int
    {
        return DB::table('branches')->insertGetId([
            'code' => $code,
            'name' => $code,
            'ref_code' => $code,
            'organization_code' => $code,
            'display_name' => $code,
        ]);
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
}
