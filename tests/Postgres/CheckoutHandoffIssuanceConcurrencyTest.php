<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Integrations\IdempotencyConflict;
use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Enums\CheckoutHandoffIntent;
use App\Models\AssessmentParticipant;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** PostgreSQL-authoritative P13a3 process overlap, lock ordering, and authority evidence. */
final class CheckoutHandoffIssuanceConcurrencyTest extends TestCase
{
    /** @var array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,sourceSystem:string} */
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
        $this->fixture = app(RlsContextRunner::class)->runAsService(fn (): array => $this->createFixture());
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('audit_logs')->where('subject_type', AssessmentParticipant::class)
                ->whereIn('subject_id', DB::table('assessment_participants')
                    ->where('organization_id', $this->fixture['organization'])->selectRaw('id::text'))->delete();
            DB::table('checkout_handoffs')->where('organization_id', $this->fixture['organization'])->delete();
            DB::table('assessment_participants')->where('organization_id', $this->fixture['organization'])->delete();
            DB::table('integration_sources')->where('integration_client_id', $this->fixture['client'])->delete();
            DB::table('integration_clients')->where('id', $this->fixture['client'])->delete();
            DB::table('participants')->where('branch_id', $this->fixture['organization'])->delete();
            DB::table('package_items')->where('package_id', $this->fixture['package'])->delete();
            DB::table('packages')->where('id', $this->fixture['package'])->delete();
            DB::table('branches')->where('id', $this->fixture['organization'])->delete();
        });
        parent::tearDown();
    }

    public function test_same_issue_in_two_processes_commits_once_and_loser_is_credentialless_replay(): void
    {
        $key = 'ih1_'.bin2hex(random_bytes(16));
        $results = $this->raceTwo(
            fn (): array => $this->issueDescriptor($this->fixture, $key, CheckoutHandoffIntent::Issue),
            fn (): array => $this->issueDescriptor($this->fixture, $key, CheckoutHandoffIntent::Issue),
        );

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['rawPresent'] === true));
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['replayed'] === true
            && $result['rawPresent'] === false && $result['reissueRequired'] === true));
        $this->assertSame([1], array_values(array_unique(array_column($results, 'issueNumber'))));
        $this->assertCount(1, array_unique(array_column($results, 'publicId')));

        app(RlsContextRunner::class)->runAsService(function () use ($results): void {
            $this->assertSame(1, DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])->count());
            $this->assertSame(1, DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])
                ->where('status', 'ISSUED')->where('active_marker', true)->count());
            $this->assertSame(1, $this->auditCount());
            $winner = array_values(array_filter($results, fn (array $result): bool => $result['rawPresent'] === true))[0];
            $this->assertSame($winner['rawDigest'], DB::table('checkout_handoffs')
                ->where('assessment_participant_id', $this->fixture['attempt'])->value('token_digest'));
        });
    }

    public function test_distinct_reissues_serialize_to_contiguous_generations_and_only_last_digest_is_active(): void
    {
        $this->issueDescriptor($this->fixture, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue);
        $before = $this->unrelatedCounts();
        $results = $this->raceTwo(
            fn (): array => $this->issueDescriptor(
                $this->fixture,
                'ih1_'.bin2hex(random_bytes(16)),
                CheckoutHandoffIntent::Reissue,
            ),
            fn (): array => $this->issueDescriptor(
                $this->fixture,
                'ih1_'.bin2hex(random_bytes(16)),
                CheckoutHandoffIntent::Reissue,
            ),
        );
        usort($results, fn (array $left, array $right): int => $left['issueNumber'] <=> $right['issueNumber']);

        $this->assertSame([2, 3], array_column($results, 'issueNumber'));
        $this->assertSame([true, true], array_column($results, 'rawPresent'));
        $this->assertSame([false, false], array_column($results, 'replayed'));
        app(RlsContextRunner::class)->runAsService(function () use ($results, $before): void {
            $rows = DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])
                ->orderBy('issue_number')->get();
            $this->assertSame([1, 2, 3], $rows->pluck('issue_number')->all());
            $this->assertSame(['REVOKED', 'REVOKED', 'ISSUED'], $rows->pluck('status')->all());
            $this->assertSame([null, null, true], $rows->pluck('active_marker')->all());
            $this->assertSame(['REISSUED', 'REISSUED', null], $rows->pluck('revocation_reason')->all());
            $this->assertSame(3, $this->auditCount());
            $this->assertSame($results[1]['rawDigest'], $rows->last()->token_digest);
            $this->assertNotSame($results[0]['rawDigest'], $rows->last()->token_digest);
            $this->assertSame($before, $this->unrelatedCounts());
        });
    }

    public function test_waiter_crossing_effective_until_is_denied_by_post_lock_database_clock(): void
    {
        $key = 'ih1_'.bin2hex(random_bytes(16));
        $result = $this->runBlocked(
            fn (): array => $this->issueDescriptor($this->fixture, $key, CheckoutHandoffIntent::Issue),
            function (): void {
                DB::table('integration_clients')->where('id', $this->fixture['client'])
                    ->lockForUpdate()->update(['effective_until' => DB::raw("clock_timestamp() + INTERVAL '1 second'")]);
                DB::table('integration_sources')->where('id', $this->fixture['source'])
                    ->lockForUpdate()->update(['effective_until' => DB::raw("clock_timestamp() + INTERVAL '1 second'")]);
                usleep(1_500_000);
                $expired = DB::selectOne('SELECT clock_timestamp() > effective_until AS expired
                    FROM integration_clients WHERE id = ?', [$this->fixture['client']]);
                $this->assertTrue($expired->expired);
            },
        );

        $this->assertSame(IntegrationContractViolation::class, $result['errorClass']);
        $this->assertSame('HANDOFF_NOT_ALLOWED', $result['errorCode']);
        $this->assertFalse($result['rawPresent']);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(0, DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])->count());
            $this->assertSame(0, $this->auditCount());
        });
    }

    /** @return iterable<string, array{string}> */
    public static function revocationTargets(): iterable
    {
        yield 'client disabled' => ['client'];
        yield 'source suspended' => ['source'];
        yield 'attempt revoked' => ['attempt'];
    }

    #[DataProvider('revocationTargets')]
    public function test_issue_waiting_behind_canonical_revocation_is_denied_without_active_handoff(string $target): void
    {
        $result = $this->runBlocked(
            fn (): array => $this->issueDescriptor(
                $this->fixture,
                'ih1_'.bin2hex(random_bytes(16)),
                CheckoutHandoffIntent::Issue,
            ),
            function () use ($target): void {
                DB::table('integration_clients')->where('id', $this->fixture['client'])->lockForUpdate()->first();
                DB::table('integration_sources')->where('id', $this->fixture['source'])->lockForUpdate()->first();
                DB::table('packages')->where('id', $this->fixture['package'])->lockForUpdate()->first();
                DB::table('package_items')->where('package_id', $this->fixture['package'])->orderBy('id')->lockForUpdate()->get();
                DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->lockForUpdate()->first();
                DB::table('participants')->where('id', $this->fixture['participant'])->lockForUpdate()->first();
                DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])
                    ->orderBy('issue_number')->orderBy('id')->lockForUpdate()->get();
                match ($target) {
                    'client' => DB::table('integration_clients')->where('id', $this->fixture['client'])->update(['enabled' => false]),
                    'source' => DB::table('integration_sources')->where('id', $this->fixture['source'])->update(['status' => 'SUSPENDED']),
                    'attempt' => DB::table('assessment_participants')->where('id', $this->fixture['attempt'])
                        ->update(['assessment_status' => 'REVOKED', 'revoked_at' => DB::raw('CURRENT_TIMESTAMP')]),
                    default => throw new RuntimeException('Unknown revocation target.'),
                };
            },
        );

        $this->assertSame(IntegrationContractViolation::class, $result['errorClass']);
        $this->assertSame('HANDOFF_NOT_ALLOWED', $result['errorCode']);
        $this->assertFalse($result['rawPresent']);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(0, DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])
                ->where('status', 'ISSUED')->where('active_marker', true)->count());
            $this->assertSame(0, $this->auditCount());
        });
    }

    public function test_issue_committing_before_waiting_revoker_is_terminalized_when_authority_becomes_invalid(): void
    {
        $workers = $this->startWorkers([fn (): array => $this->revokeDescriptor('client')]);
        try {
            $backendId = $this->workerBackendIds($workers)[0];
            $issued = app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendId): array {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertWorkerWaitsOnLock($backendId);

                return $this->issueDescriptorInCurrentContext(
                    $this->fixture,
                    'ih1_'.bin2hex(random_bytes(16)),
                    CheckoutHandoffIntent::Issue,
                );
            });
            $revoked = $this->workerResults($workers)[0];
        } finally {
            $this->stopWorkers($workers);
        }

        $this->assertTrue($issued['rawPresent']);
        $this->assertTrue($revoked['mutated']);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertFalse((bool) DB::table('integration_clients')->where('id', $this->fixture['client'])->value('enabled'));
            $row = DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])->sole();
            $this->assertSame('REVOKED', $row->status);
            $this->assertNull($row->active_marker);
            $this->assertSame('CLIENT_REVOKED', $row->revocation_reason);
            $this->assertSame(1, $this->auditCount());
        });
    }

    public function test_cross_attempt_idempotency_and_foreign_client_scope_fail_closed_on_postgres(): void
    {
        $key = 'ih1_'.bin2hex(random_bytes(16));
        $issued = $this->issueDescriptor($this->fixture, $key, CheckoutHandoffIntent::Issue);
        $otherAttempt = app(RlsContextRunner::class)->runAsService(function (): AssessmentParticipant {
            return AssessmentParticipant::query()->create([
                'integration_client_id' => $this->fixture['client'], 'organization_id' => $this->fixture['organization'],
                'participant_id' => $this->fixture['participant'], 'package_id' => $this->fixture['package'],
                'assessment_attempt_id' => (string) Str::ulid(), 'source_system' => $this->fixture['sourceSystem'],
                'external_candidate_id' => (string) Str::ulid(), 'funding_mode' => 'COMMERCIAL_SELF_PAY',
                'assessment_status' => 'PROVISIONED', 'idempotency_key' => (string) Str::ulid(),
                'request_hash' => hash('sha256', 'pg-other-request'),
                'logical_assessment_key' => hash('sha256', 'pg-other-logical'),
                'metadata' => ['checkout_contract_version' => 'checkout-v2',
                    'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY'],
            ]);
        });
        try {
            $this->issueDescriptor([...$this->fixture, 'attempt' => $otherAttempt->id,
                'attemptPublicId' => $otherAttempt->assessment_attempt_id], $key, CheckoutHandoffIntent::Issue);
            $this->fail('Idempotency key authorized a second attempt scope.');
        } catch (IdempotencyConflict $exception) {
            $this->assertSame('', $exception->getMessage());
        }

        $foreign = app(RlsContextRunner::class)->runAsService(fn (): array => $this->createFixture());
        try {
            try {
                $this->issueDescriptor([...$this->fixture, 'client' => $foreign['client']],
                    'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue);
                $this->fail('Foreign client authorized the original attempt.');
            } catch (IntegrationContractViolation $exception) {
                $this->assertSame('HANDOFF_NOT_ALLOWED', $exception->errorCode);
            }
            app(RlsContextRunner::class)->runAsService(function () use ($issued): void {
                $row = DB::table('checkout_handoffs')->where('public_id', $issued['publicId'])->sole();
                $clock = DB::selectOne('SELECT CURRENT_TIMESTAMP AS current_time');
                $this->assertLessThanOrEqual(2.0, abs(now()->parse($row->issued_at)
                    ->diffInSeconds((string) $clock->current_time, false)));
                $this->assertSame(1, DB::table('checkout_handoffs')->where('active_marker', true)
                    ->where('assessment_participant_id', $this->fixture['attempt'])->count());
                $audit = DB::table('audit_logs')->where('subject_type', AssessmentParticipant::class)
                    ->where('subject_id', (string) $this->fixture['attempt'])->sole();
                $context = json_decode($audit->context, true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(['version', 'publicId', 'issueNumber', 'purpose', 'destination', 'sourceSystem',
                    'issuedAt', 'expiresAt', 'revokedPrevious'], array_keys($context));
            });
        } finally {
            $this->cleanupFixture($foreign);
        }
    }

    /** @param array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,sourceSystem:string} $fixture
     * @return array{rawPresent:bool,rawDigest:?string,replayed:bool,reissueRequired:bool,publicId:string,issueNumber:int}
     */
    private function issueDescriptor(array $fixture, string $key, CheckoutHandoffIntent $intent): array
    {
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        $result = app(RlsContextRunner::class)->run(
            new RlsContext('service'),
            fn (): array => $this->issueDescriptorInCurrentContext($fixture, $key, $intent),
        );

        return $result;
    }

    /** @param array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,sourceSystem:string} $fixture
     * @return array{rawPresent:bool,rawDigest:?string,replayed:bool,reissueRequired:bool,publicId:string,issueNumber:int}
     */
    private function issueDescriptorInCurrentContext(array $fixture, string $key, CheckoutHandoffIntent $intent): array
    {
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        $client = IntegrationClient::query()->findOrFail($fixture['client']);
        $result = app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
            $client, $fixture['attemptPublicId'], $fixture['sourceSystem'], $key, $intent,
        ));
        $raw = $result->rawToken();
        $digest = $raw === null ? null : hash('sha256', $raw);
        $raw = null;

        return [
            'rawPresent' => $digest !== null, 'rawDigest' => $digest,
            'replayed' => $result->replayed, 'reissueRequired' => $result->reissueRequired,
            'publicId' => $result->handoffPublicId, 'issueNumber' => $result->issueNumber,
        ];
    }

    /** @return array{mutated:bool} */
    private function revokeDescriptor(string $target): array
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($target): array {
            DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
            DB::table('integration_clients')->where('id', $this->fixture['client'])->lockForUpdate()->first();
            DB::table('integration_sources')->where('id', $this->fixture['source'])->lockForUpdate()->first();
            DB::table('packages')->where('id', $this->fixture['package'])->lockForUpdate()->first();
            DB::table('package_items')->where('package_id', $this->fixture['package'])->orderBy('id')->lockForUpdate()->get();
            DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->lockForUpdate()->first();
            DB::table('participants')->where('id', $this->fixture['participant'])->lockForUpdate()->first();
            DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])
                ->orderBy('issue_number')->orderBy('id')->lockForUpdate()->get();
            if ($target !== 'client') {
                throw new RuntimeException('Unsupported fixture revocation target.');
            }
            DB::table('integration_clients')->where('id', $this->fixture['client'])->update(['enabled' => false]);
            $now = DB::raw('clock_timestamp()');
            DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])
                ->where('status', 'ISSUED')->where('active_marker', true)->update([
                    'status' => 'REVOKED', 'active_marker' => null, 'revoked_at' => $now,
                    'revocation_reason' => 'CLIENT_REVOKED', 'updated_at' => $now,
                ]);

            return ['mutated' => true];
        });
    }

    /** @param callable(): array<string,mixed> $first
     * @param  callable(): array<string,mixed>  $second
     * @return list<array<string,mixed>>
     */
    private function raceTwo(callable $first, callable $second): array
    {
        $workers = $this->startWorkers([$first, $second]);
        try {
            $backendIds = $this->workerBackendIds($workers);
            $this->assertNotSame($backendIds[0], $backendIds[1]);
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

    /** @param callable(): array<string,mixed> $worker
     * @param  callable(): void  $whileLocked
     * @return array<string,mixed>
     */
    private function runBlocked(callable $worker, callable $whileLocked): array
    {
        $workers = $this->startWorkers([$worker]);
        try {
            $backendId = $this->workerBackendIds($workers)[0];
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendId, $whileLocked): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertWorkerWaitsOnLock($backendId);
                $whileLocked();
            });

            return $this->workerResults($workers)[0];
        } finally {
            $this->stopWorkers($workers);
        }
    }

    /** @param list<callable(): array<string,mixed>> $callbacks
     * @return list<array{pid:int,socket:resource}>
     */
    private function startWorkers(array $callbacks): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'P13a3 requires pcntl; never skip.');
        DB::purge('pgsql');
        $workers = [];
        foreach ($callbacks as $callback) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false || ($pid = pcntl_fork()) === -1) {
                throw new RuntimeException('Unable to create checkout handoff worker.');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                foreach ($workers as $worker) {
                    fclose($worker['socket']);
                }
                stream_set_timeout($pair[1], 20);
                try {
                    $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name,
                        rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
                    if ($identity->name !== 'psikotes_runtime' || $identity->rolsuper || $identity->rolbypassrls) {
                        throw new RuntimeException('Worker must be runtime non-owner without RLS bypass.');
                    }
                    DB::statement("SET lock_timeout = '12s'");
                    DB::statement("SET statement_timeout = '15s'");
                    fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                    if (fgets($pair[1]) !== "go\n") {
                        throw new RuntimeException('Checkout handoff barrier timed out.');
                    }
                    $result = $callback();
                } catch (Throwable $exception) {
                    $result = [
                        'errorClass' => $exception::class,
                        'errorCode' => $exception instanceof IntegrationContractViolation
                            ? $exception->errorCode : ($exception instanceof IdempotencyConflict ? 'IDEMPOTENCY_CONFLICT' : 'UNEXPECTED'),
                        'rawPresent' => false,
                    ];
                }
                try {
                    fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                } catch (Throwable) {
                    // Parent assertion/timeout may close the socket; child must still terminate here.
                } finally {
                    fclose($pair[1]);
                    DB::disconnect('pgsql');
                    exit(0);
                }
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 20);
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
            $line = fgets($worker['socket']);
            if (! is_string($line)) {
                throw new RuntimeException('Worker did not report a backend identifier.');
            }
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $ids[] = $decoded['pid'];
        }

        return $ids;
    }

    private function assertWorkerWaitsOnLock(int $backendId): void
    {
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendId]);
            if ($waiting?->wait_event_type === 'Lock') {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->assertSame('Lock', $waiting?->wait_event_type, 'Worker must overlap and wait on a PostgreSQL lock.');
    }

    /** @param list<array{pid:int,socket:resource}> $workers
     * @return list<array<string,mixed>>
     */
    private function workerResults(array $workers): array
    {
        $results = [];
        foreach ($workers as $worker) {
            $line = fgets($worker['socket']);
            if (! is_string($line)) {
                throw new RuntimeException('Worker did not report a result.');
            }
            $results[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
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

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,sourceSystem:string} */
    private function createFixture(): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'P13_'.$key;
        $packageCode = 'P13'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'P13 Synthetic', 'organization_code' => $key,
            'display_name' => 'P13 Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization, 'referral_source' => 'manual',
            'source_system' => $sourceSystem, 'full_name' => 'P13 Synthetic', 'phone' => '620000000000',
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
            'code' => $packageCode, 'name' => 'P13 Synthetic', 'amount' => 100, 'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptPublicId = (string) Str::ulid();
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY'], JSON_THROW_ON_ERROR),
        ]);

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt', 'attemptPublicId', 'sourceSystem');
    }

    /** @param array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,sourceSystem:string} $fixture */
    private function cleanupFixture(array $fixture): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($fixture): void {
            DB::table('audit_logs')->where('subject_type', AssessmentParticipant::class)
                ->whereIn('subject_id', DB::table('assessment_participants')
                    ->where('organization_id', $fixture['organization'])->selectRaw('id::text'))->delete();
            DB::table('checkout_handoffs')->where('organization_id', $fixture['organization'])->delete();
            DB::table('assessment_participants')->where('organization_id', $fixture['organization'])->delete();
            DB::table('integration_sources')->where('integration_client_id', $fixture['client'])->delete();
            DB::table('integration_clients')->where('id', $fixture['client'])->delete();
            DB::table('participants')->where('branch_id', $fixture['organization'])->delete();
            DB::table('package_items')->where('package_id', $fixture['package'])->delete();
            DB::table('packages')->where('id', $fixture['package'])->delete();
            DB::table('branches')->where('id', $fixture['organization'])->delete();
        });
    }

    private function auditCount(): int
    {
        return DB::table('audit_logs')->where('subject_type', AssessmentParticipant::class)
            ->where('subject_id', (string) $this->fixture['attempt'])->count();
    }

    /** @return array<string,int> */
    private function unrelatedCounts(): array
    {
        $counts = [];
        foreach (['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements',
            'orders', 'entitlements', 'outbox_messages', 'identity_evidence', 'identity_verifications', 'sessions'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
