<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Integrations\CheckoutSessionLifecycle;
use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\InvalidCheckoutSession;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Enums\CheckoutHandoffIntent;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ForkedProcessResult;
use Throwable;

/** PostgreSQL-authoritative serialization evidence for the P16 mutation seam. */
final class CheckoutSessionMutationScopeConcurrencyTest extends TestCase
{
    /** @var array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,session:int,selector:string,csrf:string} */
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
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
        ]);
        $graph = app(RlsContextRunner::class)->runAsService(fn (): array => $this->createFixture());
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(
                IntegrationClient::query()->findOrFail($graph['client']), $graph['attemptPublicId'],
                $graph['sourceSystem'], 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue,
            )));
        $raw = $issued->rawToken();
        if (! is_string($raw)) {
            throw new RuntimeException('Synthetic handoff unavailable.');
        }
        $established = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($raw));
        $session = app(RlsContextRunner::class)->runAsService(
            fn (): mixed => CheckoutSession::query()->where('public_id', $established->sessionPublicId)->value('id'),
        );
        if (! is_int($session)) {
            throw new RuntimeException('Synthetic checkout session unavailable.');
        }
        $this->fixture = [...$graph, 'session' => $session,
            'selector' => $established->rawSelector(), 'csrf' => $established->rawCsrfToken()];
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('checkout_sessions')->where('organization_id', $this->fixture['organization'])->delete();
            DB::table('checkout_handoffs')->where('organization_id', $this->fixture['organization'])->delete();
            DB::table('integration_sources')->where('integration_client_id', $this->fixture['client'])->delete();
            DB::table('package_items')->where('package_id', $this->fixture['package'])->delete();
        });
        parent::tearDown();
    }

    public function test_mutation_waits_for_policy_commit_then_rejects_stale_authority(): void
    {
        $credentials = new CheckoutSessionMutationCredentials($this->fixture['selector'], $this->fixture['csrf']);
        $snapshot = app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery($credentials);
        $this->assertSame($this->fixture['attempt'], $snapshot->assessmentParticipantId);

        $worker = $this->startWorker(function () use ($credentials): array {
            try {
                app(RlsContextRunner::class)->runAsService(
                    fn () => app(CheckoutSessionLifecycle::class)->lockMutation($credentials)->principal(),
                );

                return ['result' => 'authorized'];
            } catch (InvalidCheckoutSession) {
                return [
                    'result' => 'invalid',
                    'context' => app(RlsContextRunner::class)->current()?->role,
                    'transaction' => DB::transactionLevel(),
                ];
            }
        });

        try {
            $backendId = $this->workerBackendId($worker);
            app(RlsContextRunner::class)->runAsService(function () use ($worker, $backendId): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($worker['socket'], "go\n");
                $this->assertWorkerWaitsOnLock($backendId);
                DB::table('branches')->where('id', $this->fixture['organization'])->update([
                    'allowed_payer_types' => json_encode(['organization'], JSON_THROW_ON_ERROR),
                ]);
            });
            $this->assertSame(
                ['result' => 'invalid', 'context' => null, 'transaction' => 0],
                $this->workerResult($worker),
            );
        } finally {
            $this->stopWorker($worker);
        }

        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(0, DB::table('assessment_bills')->where('organization_id', $this->fixture['organization'])->count());
            $this->assertSame(0, DB::table('assessment_charges')->where('assessment_participant_id', $this->fixture['attempt'])->count());
            $this->assertSame(0, DB::table('outbox_messages')->where('aggregate_id', (string) $this->fixture['attempt'])->count());
        });
    }

    /** @param callable():array<string,mixed> $callback
     * @return array{pid:int,socket:resource}
     */
    private function startWorker(callable $callback): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'P16-pay-a requires pcntl; never skip.');
        DB::purge('pgsql');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create mutation-scope worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 20);
            try {
                $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
                if ($identity->name !== 'psikotes_runtime' || $identity->rolsuper || $identity->rolbypassrls) {
                    throw new RuntimeException('Worker must be runtime non-owner without RLS bypass.');
                }
                DB::statement("SET lock_timeout = '12s'");
                DB::statement("SET statement_timeout = '15s'");
                fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Mutation-scope barrier timed out.');
                }
                $result = $callback();
            } catch (Throwable $exception) {
                $result = ['unexpected' => $exception::class, 'message' => $exception->getMessage()];
            }
            ForkedProcessResult::sendAndExit($pair[1], $result,
                static function (): void {
                    DB::disconnect('pgsql');
                });
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 20);

        return ['pid' => $pid, 'socket' => $pair[0]];
    }

    /** @param array{pid:int,socket:resource} $worker */
    private function workerBackendId(array $worker): int
    {
        $line = fgets($worker['socket']);
        if (! is_string($line)) {
            throw new RuntimeException('Worker did not report backend identity.');
        }

        return json_decode($line, true, flags: JSON_THROW_ON_ERROR)['pid'];
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
        $this->assertSame('Lock', $waiting?->wait_event_type);
    }

    /** @param array{pid:int,socket:resource} $worker
     * @return array<string,mixed>
     */
    private function workerResult(array $worker): array
    {
        $line = fgets($worker['socket']);
        if (! is_string($line)) {
            throw new RuntimeException('Worker did not report result.');
        }

        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array{pid:int,socket:resource} $worker */
    private function stopWorker(array $worker): void
    {
        fclose($worker['socket']);
        pcntl_waitpid($worker['pid'], $status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,attemptPublicId:string,sourceSystem:string} */
    private function createFixture(): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'P16PAYA_'.$key;
        $packageCode = 'P16'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'P16 Synthetic', 'organization_code' => $key,
            'display_name' => 'P16 Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
            'allowed_payer_types' => json_encode(['self'], JSON_THROW_ON_ERROR),
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => 'P16 Synthetic', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]',
            'allowed_payer_types' => json_encode(['self'], JSON_THROW_ON_ERROR),
            'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'P16 Synthetic', 'amount' => 100,
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
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
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
