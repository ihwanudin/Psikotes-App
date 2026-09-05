<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\InvalidCheckoutHandoff;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Enums\CheckoutHandoffIntent;
use App\Models\AssessmentParticipant;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** PostgreSQL-authoritative P14a2 consume/session atomicity and recovery races. */
final class CheckoutSessionEstablishmentConcurrencyTest extends TestCase
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
        $this->configure();
        $this->fixture = app(RlsContextRunner::class)->runAsService(fn (): array => $this->createFixture());
    }

    protected function tearDown(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('audit_logs')->where('subject_type', AssessmentParticipant::class)
                ->where('subject_id', (string) $this->fixture['attempt'])->delete();
            DB::table('checkout_sessions')->where('organization_id', $this->fixture['organization'])->delete();
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

    public function test_same_bearer_two_processes_create_one_session_after_real_lock_wait(): void
    {
        $raw = $this->issue(CheckoutHandoffIntent::Issue);
        $results = $this->raceTwo(
            fn (): array => $this->establishDescriptor($raw),
            fn (): array => $this->establishDescriptor($raw),
        );

        $this->assertCount(1, array_filter($results, fn (array $row): bool => ($row['established'] ?? false) === true));
        $this->assertCount(1, array_filter($results, fn (array $row): bool => ($row['invalid'] ?? false) === true));
        foreach ($results as $result) {
            $this->assertArrayNotHasKey('selector', $result);
            $this->assertArrayNotHasKey('csrf', $result);
        }
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(1, DB::table('checkout_sessions')->where('assessment_participant_id', $this->fixture['attempt'])->count());
            $this->assertSame(1, DB::table('checkout_sessions')->where('assessment_participant_id', $this->fixture['attempt'])
                ->where('status', 'ACTIVE')->count());
            $this->assertSame(1, $this->auditCount('checkout_handoff.consumed'));
            $this->assertSame(1, $this->auditCount('checkout_session.established'));
        });
    }

    public function test_establish_and_recovery_are_linearizable_without_two_active_authorities(): void
    {
        $raw = $this->issue(CheckoutHandoffIntent::Issue);
        $results = $this->raceTwo(
            fn (): array => $this->establishDescriptor($raw),
            fn (): array => $this->recoveryDescriptor(),
        );

        $this->assertTrue(($results[0]['established'] ?? false) === true);
        app(RlsContextRunner::class)->runAsService(function () use ($results): void {
            $activeSessions = DB::table('checkout_sessions')->where('assessment_participant_id', $this->fixture['attempt'])
                ->where('status', 'ACTIVE')->count();
            $activeHandoffs = DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])
                ->where('status', 'ISSUED')->where('active_marker', true)->count();
            $this->assertLessThanOrEqual(1, $activeSessions);
            $this->assertLessThanOrEqual(1, $activeHandoffs);
            $this->assertFalse($activeSessions === 1 && $activeHandoffs === 1);
            if (($results[1]['recovered'] ?? false) === true) {
                $this->assertSame(0, $activeSessions);
                $this->assertSame(1, $activeHandoffs);
                $this->assertSame(1, $this->auditCount('checkout_handoff.recovery_reissued'));
            } else {
                $this->assertSame('HANDOFF_RECOVERY_NOT_ALLOWED', $results[1]['errorCode'] ?? null);
                $this->assertSame(1, $activeSessions);
                $this->assertSame(0, $activeHandoffs);
            }
        });
    }

    /** @return array{established:bool,invalid:bool,selectorPresent?:bool,csrfPresent?:bool} */
    private function establishDescriptor(#[\SensitiveParameter] string $raw): array
    {
        $this->configure();
        try {
            $result = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($raw));

            return [
                'established' => true, 'invalid' => false,
                'selectorPresent' => preg_match('/^ocs1_[0-9a-f]{64}$/D', $result->rawSelector()) === 1,
                'csrfPresent' => preg_match('/^ocsrf1_[0-9a-f]{64}$/D', $result->rawCsrfToken()) === 1,
            ];
        } catch (InvalidCheckoutHandoff) {
            return ['established' => false, 'invalid' => true];
        }
    }

    /** @return array{recovered:bool,errorCode:?string} */
    private function recoveryDescriptor(): array
    {
        $this->configure();
        try {
            $this->issue(CheckoutHandoffIntent::Recovery);

            return ['recovered' => true, 'errorCode' => null];
        } catch (IntegrationContractViolation $exception) {
            return ['recovered' => false, 'errorCode' => $exception->errorCode];
        }
    }

    private function configure(): void
    {
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
        ]);
    }

    private function issue(CheckoutHandoffIntent $intent): string
    {
        return app(RlsContextRunner::class)->run(new RlsContext('service'), function () use ($intent): string {
            $result = app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
                IntegrationClient::query()->findOrFail($this->fixture['client']),
                $this->fixture['attemptPublicId'], $this->fixture['sourceSystem'],
                'ih1_'.bin2hex(random_bytes(16)), $intent,
            ));
            $raw = $result->rawToken();
            if (! is_string($raw)) {
                throw new RuntimeException('Synthetic issuance did not return a bearer.');
            }

            return $raw;
        });
    }

    /** @param callable():array<string,mixed> $first
     * @param  callable():array<string,mixed>  $second
     * @return list<array<string,mixed>>
     */
    private function raceTwo(callable $first, callable $second): array
    {
        $workers = $this->startWorkers([$first, $second]);
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

    /** @param list<callable():array<string,mixed>> $callbacks
     * @return list<array{pid:int,socket:resource}>
     */
    private function startWorkers(array $callbacks): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'P14a2 requires pcntl; never skip.');
        DB::purge('pgsql');
        $workers = [];
        foreach ($callbacks as $callback) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false || ($pid = pcntl_fork()) === -1) {
                throw new RuntimeException('Unable to create checkout-session worker.');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                foreach ($workers as $worker) {
                    fclose($worker['socket']);
                }
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
                        throw new RuntimeException('Checkout-session barrier timed out.');
                    }
                    $result = $callback();
                } catch (Throwable $exception) {
                    $result = ['unexpected' => $exception::class, 'message' => $exception->getMessage()];
                }
                fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                fclose($pair[1]);
                DB::disconnect('pgsql');
                exit(0);
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
                throw new RuntimeException('Worker did not report backend identity.');
            }
            $ids[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR)['pid'];
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
        $this->assertSame('Lock', $waiting?->wait_event_type);
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
                throw new RuntimeException('Worker did not report result.');
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
        $sourceSystem = 'P14A2_'.$key;
        $packageCode = 'P14A2'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'P14a2 Synthetic', 'organization_code' => $key,
            'display_name' => 'P14a2 Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization, 'referral_source' => 'manual',
            'source_system' => $sourceSystem, 'full_name' => 'P14a2 Synthetic', 'phone' => '620000000000',
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
            'code' => $packageCode, 'name' => 'P14a2 Synthetic', 'amount' => 100,
            'currency' => 'IDR', 'is_active' => true,
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

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt',
            'attemptPublicId', 'sourceSystem');
    }

    private function auditCount(string $action): int
    {
        return DB::table('audit_logs')->where('subject_type', AssessmentParticipant::class)
            ->where('subject_id', (string) $this->fixture['attempt'])->where('action', $action)->count();
    }
}
