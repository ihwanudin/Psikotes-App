<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Integrations\ConsumeCheckoutHandoff;
use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffConsumeInput;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Enums\CheckoutHandoffIntent;
use App\Models\AssessmentParticipant;
use App\Models\CheckoutHandoff;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ForkedProcessResult;
use Throwable;

/** PostgreSQL-authoritative P13 recovery serialization and revocation races. */
final class CheckoutHandoffRecoveryConcurrencyTest extends TestCase
{
    /** @var array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,sourceSystem:string,handoff:int,session:int} */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $identity = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        $graph = app(RlsContextRunner::class)->runAsService(fn (): array => $this->createFixture());
        $issued = $this->issue($graph, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue);
        $raw = $issued['raw'];
        $this->assertIsString($raw);
        app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));
        $this->fixture = app(RlsContextRunner::class)->runAsService(function () use ($graph, $issued, $raw): array {
            $handoff = CheckoutHandoff::query()->where('public_id', $issued['publicId'])->firstOrFail();
            $at = $handoff->consumed_at?->toImmutable();
            if (! $at instanceof CarbonImmutable) {
                throw new RuntimeException('Consumed handoff has no canonical timestamp.');
            }
            $session = CheckoutSession::query()->create([
                'public_id' => (string) Str::ulid(), 'selector_digest' => hash('sha256', 'selector-'.$raw),
                'csrf_digest' => hash('sha256', 'csrf-'.$raw), 'checkout_handoff_id' => $handoff->id,
                'assessment_participant_id' => $graph['attempt'], 'organization_id' => $graph['organization'],
                'participant_id' => $graph['participant'], 'package_id' => $graph['package'],
                'integration_client_id' => $graph['client'], 'integration_source_id' => $graph['source'],
                'source_system' => $graph['sourceSystem'], 'contract_version' => 'checkout-v2',
                'status' => 'ACTIVE', 'active_marker' => true, 'established_at' => $at, 'last_seen_at' => $at,
                'idle_expires_at' => $at->addMinutes(30), 'absolute_expires_at' => $at->addHours(2),
            ]);

            return [...$graph, 'handoff' => $handoff->id, 'session' => $session->id];
        });
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('audit_logs')->where('subject_type', AssessmentParticipant::class)
                ->whereIn('subject_id', DB::table('assessment_participants')
                    ->where('organization_id', $this->fixture['organization'])->selectRaw('id::text'))->delete();
            DB::table('checkout_sessions')->where('organization_id', $this->fixture['organization'])->delete();
            DB::table('checkout_handoffs')->where('organization_id', $this->fixture['organization'])->delete();
            DB::table('integration_sources')->where('integration_client_id', $this->fixture['client'])->delete();
        });
        // Not the service-role connection above: package_items has no
        // DELETE grant at all (RLS-GAP-07/08 remediation, 2026-09-22 --
        // no production code path ever deletes a package or package item,
        // so it was deliberately not granted). Test cleanup goes through
        // the schema-owner connection instead, same as every other
        // owner-only DDL/cleanup operation in this suite.
        $this->deletePackageItems($this->fixture['package']);
        parent::tearDown();
    }

    private function deletePackageItems(int $packageId): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.checkout_handoff_recovery_cleanup', [
            ...$config,
            'username' => 'org_test_owner',
        ]);

        try {
            DB::connection('checkout_handoff_recovery_cleanup')
                ->table('package_items')->where('package_id', $packageId)->delete();
        } finally {
            DB::purge('checkout_handoff_recovery_cleanup');
            config()->set('database.connections.checkout_handoff_recovery_cleanup', null);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function restartStates(): iterable
    {
        yield 'active' => ['ACTIVE'];
        yield 'expired' => ['EXPIRED'];
        yield 'logout' => ['LOGOUT'];
    }

    #[DataProvider('restartStates')]
    public function test_distinct_recoveries_serialize_to_one_new_active_handoff(string $state): void
    {
        app(RlsContextRunner::class)->runAsService(fn () => $this->terminalizeSession($state));
        $results = $this->raceTwo(
            'ih1_'.bin2hex(random_bytes(16)),
            'ih1_'.bin2hex(random_bytes(16)),
        );

        $this->assertCount(1, array_filter(
            $results, fn (array $result): bool => $result['rawPresent'] === true,
        ), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertCount(1, array_filter($results, fn (array $result): bool => ($result['errorCode'] ?? null) === 'HANDOFF_RECOVERY_NOT_ALLOWED'));
        app(RlsContextRunner::class)->runAsService(function () use ($state): void {
            $rows = DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])
                ->orderBy('issue_number')->get();
            $this->assertSame([1, 2], $rows->pluck('issue_number')->all());
            $this->assertSame(['CONSUMED', 'ISSUED'], $rows->pluck('status')->all());
            $this->assertSame([null, true], $rows->pluck('active_marker')->all());
            $session = DB::table('checkout_sessions')->where('id', $this->fixture['session'])->sole();
            $this->assertNull($session->active_marker);
            $this->assertSame($state === 'EXPIRED' ? 'EXPIRED' : 'REVOKED', $session->status);
            $this->assertSame(match ($state) {
                'ACTIVE' => 'RECOVERY_REISSUED', 'EXPIRED' => null, 'LOGOUT' => 'LOGOUT',
            }, $session->revocation_reason);
            $this->assertSame(1, $this->recoveryAuditCount());
            $context = json_decode(DB::table('audit_logs')
                ->where('action', 'checkout_handoff.recovery_reissued')
                ->where('subject_id', (string) $this->fixture['attempt'])->value('context'),
                true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(match ($state) {
                'ACTIVE' => 'ACTIVE_REVOKED', 'EXPIRED' => 'EXPIRED', 'LOGOUT' => 'LOGOUT',
            }, $context['priorSessionState']);
        });
    }

    #[DataProvider('restartStates')]
    public function test_same_key_recovery_has_one_credential_and_one_credentialless_replay(string $state): void
    {
        app(RlsContextRunner::class)->runAsService(fn () => $this->terminalizeSession($state));
        $key = 'ih1_'.bin2hex(random_bytes(16));
        $results = $this->raceTwo($key, $key);

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['rawPresent'] === true));
        $this->assertCount(1, array_filter($results, fn (array $result): bool => ($result['replayed'] ?? false) === true && $result['rawPresent'] === false));
        $this->assertSame([2], array_values(array_unique(array_column($results, 'issueNumber'))));
        $this->assertCount(1, array_unique(array_column($results, 'publicId')));
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(2, DB::table('checkout_handoffs')
                ->where('assessment_participant_id', $this->fixture['attempt'])->count());
            $this->assertSame(1, $this->recoveryAuditCount());
        });
    }

    /** @return iterable<string, array{string, bool}> */
    public static function concurrentTerminalStates(): iterable
    {
        yield 'natural expiry' => ['EXPIRED', true];
        yield 'logout' => ['LOGOUT', true];
        yield 'scope revocation' => ['SCOPE_REVOKED', false];
    }

    #[DataProvider('concurrentTerminalStates')]
    public function test_recovery_is_linearizable_with_session_terminalization(string $state, bool $allowed): void
    {
        $workers = $this->startWorkers(['ih1_'.bin2hex(random_bytes(16))]);
        try {
            $backend = $this->workerBackendIds($workers)[0];
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backend, $state): void {
                $this->lockCanonicalGraph();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertWorkerWaitsOnLock($backend);
                $this->terminalizeSession($state);
            });
            $result = $this->workerResults($workers)[0];
        } finally {
            $this->stopWorkers($workers);
        }

        if ($allowed) {
            $this->assertTrue($result['rawPresent'], json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertSame(2, $result['issueNumber']);
        } else {
            $this->assertSame(IntegrationContractViolation::class, $result['errorClass']);
            $this->assertSame('HANDOFF_RECOVERY_NOT_ALLOWED', $result['errorCode']);
            $this->assertFalse($result['rawPresent']);
        }
        app(RlsContextRunner::class)->runAsService(function () use ($allowed, $state): void {
            $this->assertSame($allowed ? 2 : 1, DB::table('checkout_handoffs')
                ->where('assessment_participant_id', $this->fixture['attempt'])->count());
            $this->assertSame('CONSUMED', DB::table('checkout_handoffs')
                ->where('id', $this->fixture['handoff'])->value('status'));
            $this->assertSame($state === 'EXPIRED' ? 'EXPIRED' : 'REVOKED', DB::table('checkout_sessions')
                ->where('id', $this->fixture['session'])->value('status'));
            $this->assertSame($allowed ? 1 : 0, $this->recoveryAuditCount());
        });
    }

    /** @return list<array<string, mixed>> */
    private function raceTwo(string $firstKey, string $secondKey): array
    {
        $workers = $this->startWorkers([$firstKey, $secondKey]);
        try {
            $backendIds = $this->workerBackendIds($workers);
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendIds): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                foreach ($workers as $index => $worker) {
                    fwrite($worker['socket'], "go\n");
                    $this->assertWorkerWaitsOnLock($backendIds[$index]);
                }
            });

            return $this->workerResults($workers);
        } finally {
            $this->stopWorkers($workers);
        }
    }

    /** @param list<string> $keys
     * @return list<array{pid:int,socket:resource}>
     */
    private function startWorkers(array $keys): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $workers = [];
        foreach ($keys as $key) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false || ($pid = pcntl_fork()) === -1) {
                throw new RuntimeException('Unable to create recovery worker.');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                foreach ($workers as $worker) {
                    fclose($worker['socket']);
                }
                stream_set_timeout($pair[1], 15);
                try {
                    $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name');
                    if ($identity->name !== 'psikotes_runtime') {
                        throw new RuntimeException('Worker must use runtime role.');
                    }
                    DB::statement("SET lock_timeout = '10s'");
                    DB::statement("SET statement_timeout = '12s'");
                    fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                    if (fgets($pair[1]) !== "go\n") {
                        throw new RuntimeException('Recovery barrier timed out.');
                    }
                    $result = $this->issue($this->fixture, $key, CheckoutHandoffIntent::Recovery);
                    $payload = [
                        'publicId' => $result['publicId'], 'issueNumber' => $result['issueNumber'],
                        'replayed' => $result['replayed'], 'rawPresent' => is_string($result['raw']),
                    ];
                } catch (Throwable $exception) {
                    $payload = [
                        'errorClass' => $exception::class,
                        'errorCode' => $exception instanceof IntegrationContractViolation
                            ? $exception->errorCode : $exception->getMessage(),
                        'errorLine' => $exception->getLine(),
                        'rawPresent' => false,
                    ];
                }
                ForkedProcessResult::sendAndExit($pair[1], $payload,
                    static function (): void {
                        DB::disconnect('pgsql');
                    });
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 15);
            $workers[] = ['pid' => $pid, 'socket' => $pair[0]];
        }

        return $workers;
    }

    /** @param list<array{pid:int,socket:resource}> $workers
     * @return list<int>
     */
    private function workerBackendIds(array $workers): array
    {
        $ids = [];
        foreach ($workers as $worker) {
            $message = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
            $ids[] = $message['pid'];
        }

        return $ids;
    }

    /** @param list<array{pid:int,socket:resource}> $workers
     * @return list<array<string, mixed>>
     */
    private function workerResults(array $workers): array
    {
        return array_map(
            fn (array $worker): array => json_decode(
                (string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR,
            ),
            $workers,
        );
    }

    /** @param list<array{pid:int,socket:resource}> $workers */
    private function stopWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    private function assertWorkerWaitsOnLock(int $backendId): void
    {
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendId]);
            if ($waiting?->wait_event_type === 'Lock') {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertSame('Lock', $waiting?->wait_event_type, 'Recovery worker did not overlap on a real lock.');
    }

    private function lockCanonicalGraph(): void
    {
        DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
        DB::table('integration_clients')->where('id', $this->fixture['client'])->lockForUpdate()->first();
        DB::table('integration_sources')->where('id', $this->fixture['source'])->lockForUpdate()->first();
        DB::table('packages')->where('id', $this->fixture['package'])->lockForUpdate()->first();
        DB::table('package_items')->where('package_id', $this->fixture['package'])->orderBy('id')->lockForUpdate()->get();
        DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->lockForUpdate()->first();
        DB::table('participants')->where('id', $this->fixture['participant'])->lockForUpdate()->first();
        DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])
            ->orderBy('issue_number')->orderBy('id')->lockForUpdate()->get();
        DB::table('checkout_sessions')->where('assessment_participant_id', $this->fixture['attempt'])
            ->orderBy('established_at')->orderBy('id')->lockForUpdate()->get();
    }

    private function terminalizeSession(string $state): void
    {
        if ($state === 'ACTIVE') {
            return;
        }
        if ($state === 'EXPIRED') {
            DB::table('checkout_handoffs')->where('id', $this->fixture['handoff'])->update([
                'issued_at' => DB::raw("clock_timestamp() - INTERVAL '9 minutes'"),
                'consumed_at' => DB::raw("clock_timestamp() - INTERVAL '8 minutes'"),
                'expires_at' => DB::raw("clock_timestamp() + INTERVAL '1 minute'"),
            ]);
            DB::table('checkout_sessions')->where('id', $this->fixture['session'])->update([
                'status' => 'EXPIRED', 'active_marker' => null,
                'established_at' => DB::raw("clock_timestamp() - INTERVAL '8 minutes'"),
                'last_seen_at' => DB::raw("clock_timestamp() - INTERVAL '8 minutes'"),
                'idle_expires_at' => DB::raw("clock_timestamp() - INTERVAL '7 minutes'"),
                'absolute_expires_at' => DB::raw("clock_timestamp() + INTERVAL '30 minutes'"),
                'expired_at' => DB::raw("clock_timestamp() - INTERVAL '7 minutes'"),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);

            return;
        }
        if (! in_array($state, ['LOGOUT', 'SCOPE_REVOKED'], true)) {
            throw new RuntimeException('Unknown synthetic checkout session terminal state.');
        }
        DB::table('checkout_sessions')->where('id', $this->fixture['session'])->update([
            'status' => 'REVOKED', 'active_marker' => null, 'revoked_at' => DB::raw('clock_timestamp()'),
            'revocation_reason' => $state, 'updated_at' => DB::raw('clock_timestamp()'),
        ]);
    }

    private function recoveryAuditCount(): int
    {
        return DB::table('audit_logs')->where('subject_type', AssessmentParticipant::class)
            ->where('subject_id', (string) $this->fixture['attempt'])
            ->where('action', 'checkout_handoff.recovery_reissued')->count();
    }

    /** @param array{client:int,attemptPublicId:string,sourceSystem:string} $fixture
     * @return array{publicId:string,issueNumber:int,replayed:bool,raw:?string}
     */
    private function issue(array $fixture, string $key, CheckoutHandoffIntent $intent): array
    {
        return app(RlsContextRunner::class)->run(
            new RlsContext('service'),
            function () use ($fixture, $key, $intent): array {
                $result = app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
                    IntegrationClient::query()->findOrFail($fixture['client']),
                    $fixture['attemptPublicId'], $fixture['sourceSystem'], $key, $intent,
                ));

                return [
                    'publicId' => $result->handoffPublicId, 'issueNumber' => $result->issueNumber,
                    'replayed' => $result->replayed, 'raw' => $result->rawToken(),
                ];
            },
        );
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,sourceSystem:string} */
    private function createFixture(): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'HANDOFF_RECOVERY_PG';
        $packageCode = 'R'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic', 'amount' => 100,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptPublicId = (string) Str::ulid();
        $timestamp = now();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $attemptPublicId, 'participant_id' => $participant,
            'organization_id' => $organization, 'package_id' => $package, 'origin' => 'INTEGRATED',
            'intended_field_snapshot' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_case_id' => $case, 'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY',
            ], JSON_THROW_ON_ERROR),
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt',
            'attemptPublicId', 'sourceSystem');
    }
}
