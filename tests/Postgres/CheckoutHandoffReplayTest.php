<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Integrations\ConsumeCheckoutHandoff;
use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\InvalidCheckoutHandoff;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffConsumeInput;
use App\Data\Integrations\CheckoutHandoffIssueInput;
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

/** PostgreSQL-authoritative P13b one-time bearer and canonical-lock evidence. */
final class CheckoutHandoffReplayTest extends TestCase
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
                ->where('subject_id', (string) $this->fixture['attempt'])->delete();
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
        config()->set('database.connections.checkout_handoff_replay_cleanup', [
            ...$config,
            'username' => 'org_test_owner',
        ]);

        try {
            DB::connection('checkout_handoff_replay_cleanup')
                ->table('package_items')->where('package_id', $packageId)->delete();
        } finally {
            DB::purge('checkout_handoff_replay_cleanup');
            config()->set('database.connections.checkout_handoff_replay_cleanup', null);
        }
    }

    public function test_same_bearer_in_two_processes_has_exactly_one_winner_after_real_lock_wait(): void
    {
        $raw = $this->issue();
        $results = $this->raceTwo(
            fn (): array => $this->consumeDescriptor($raw),
            fn (): array => $this->consumeDescriptor($raw),
        );

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['consumed'] === true));
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['invalid'] === true));
        app(RlsContextRunner::class)->runAsService(function (): void {
            $row = DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])->sole();
            $this->assertSame('CONSUMED', $row->status);
            $this->assertNull($row->active_marker);
            $this->assertNotNull($row->consumed_at);
            $this->assertSame(1, $this->consumeAuditCount());
        });
    }

    public function test_consume_and_reissue_are_linearized_without_two_valid_bearers(): void
    {
        $raw = $this->issue();
        $results = $this->raceTwo(
            fn (): array => $this->consumeDescriptor($raw),
            fn (): array => $this->reissueDescriptor(),
        );

        $consume = $results[0];
        $reissue = $results[1];
        $this->assertNotSame($consume['consumed'], $reissue['reissued']);
        app(RlsContextRunner::class)->runAsService(function () use ($consume): void {
            $rows = DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])
                ->orderBy('issue_number')->get();
            if ($consume['consumed']) {
                $this->assertSame(['CONSUMED'], $rows->pluck('status')->all());
                $this->assertSame(0, $rows->where('status', 'ISSUED')->count());
                $this->assertSame(1, $this->consumeAuditCount());
            } else {
                $this->assertSame(['REVOKED', 'ISSUED'], $rows->pluck('status')->all());
                $this->assertSame([null, true], $rows->pluck('active_marker')->all());
                $this->assertSame(0, $this->consumeAuditCount());
            }
        });
    }

    public function test_consumer_waiting_behind_revocation_fails_closed_without_consumption(): void
    {
        $raw = $this->issue();
        $workers = $this->startWorkers([fn (): array => $this->consumeDescriptor($raw)]);
        try {
            $backendId = $this->workerBackendIds($workers)[0];
            app(RlsContextRunner::class)->runAsService(function () use ($workers, $backendId): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($workers[0]['socket'], "go\n");
                $this->assertWorkerWaitsOnLock($backendId);
                DB::table('integration_clients')->where('id', $this->fixture['client'])->lockForUpdate()
                    ->update(['enabled' => false]);
                $now = DB::raw('clock_timestamp()');
                DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])
                    ->where('status', 'ISSUED')->where('active_marker', true)->update([
                        'status' => 'REVOKED', 'active_marker' => null, 'revoked_at' => $now,
                        'revocation_reason' => 'CLIENT_REVOKED', 'updated_at' => $now,
                    ]);
            });
            $result = $this->workerResults($workers)[0];
        } finally {
            $this->stopWorkers($workers);
        }

        $this->assertTrue($result['invalid']);
        $this->assertFalse($result['consumed']);
        app(RlsContextRunner::class)->runAsService(function (): void {
            $row = DB::table('checkout_handoffs')->where('assessment_participant_id', $this->fixture['attempt'])->sole();
            $this->assertSame('REVOKED', $row->status);
            $this->assertNull($row->consumed_at);
            $this->assertSame(0, $this->consumeAuditCount());
        });
    }

    /** @return array{consumed:bool,invalid:bool} */
    private function consumeDescriptor(#[\SensitiveParameter] string $raw): array
    {
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        try {
            app(ConsumeCheckoutHandoff::class)->execute(new CheckoutHandoffConsumeInput($raw));

            return ['consumed' => true, 'invalid' => false];
        } catch (InvalidCheckoutHandoff) {
            return ['consumed' => false, 'invalid' => true];
        }
    }

    /** @return array{reissued:bool,invalid:bool} */
    private function reissueDescriptor(): array
    {
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        try {
            app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
                app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
                    IntegrationClient::query()->findOrFail($this->fixture['client']),
                    $this->fixture['attemptPublicId'],
                    $this->fixture['sourceSystem'],
                    'ih1_'.bin2hex(random_bytes(16)),
                    CheckoutHandoffIntent::Reissue,
                ));
            });

            return ['reissued' => true, 'invalid' => false];
        } catch (IntegrationContractViolation) {
            return ['reissued' => false, 'invalid' => true];
        }
    }

    private function issue(): string
    {
        $result = app(RlsContextRunner::class)->run(new RlsContext('service'), function () {
            return app(IssueCheckoutHandoff::class)->execute(new CheckoutHandoffIssueInput(
                IntegrationClient::query()->findOrFail($this->fixture['client']),
                $this->fixture['attemptPublicId'],
                $this->fixture['sourceSystem'],
                'ih1_'.bin2hex(random_bytes(16)),
                CheckoutHandoffIntent::Issue,
            ));
        });
        $raw = $result->rawToken();
        if ($raw === null) {
            throw new RuntimeException('Synthetic issuance did not produce a bearer.');
        }

        return $raw;
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

    /** @param list<callable(): array<string,mixed>> $callbacks
     * @return list<array{pid:int,socket:resource}>
     */
    private function startWorkers(array $callbacks): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'P13b requires pcntl; never skip.');
        DB::purge('pgsql');
        $workers = [];
        foreach ($callbacks as $callback) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false || ($pid = pcntl_fork()) === -1) {
                throw new RuntimeException('Unable to create checkout handoff consume worker.');
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
                        throw new RuntimeException('Checkout handoff consume barrier timed out.');
                    }
                    $result = $callback();
                } catch (Throwable $exception) {
                    $result = ['consumed' => false, 'invalid' => false, 'unexpected' => $exception::class];
                }
                try {
                    fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                } catch (Throwable) {
                    // Parent timeout may close the socket; the child must still terminate.
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
        $sourceSystem = 'P13B_'.$key;
        $packageCode = 'P13B'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'P13b Synthetic', 'organization_code' => $key,
            'display_name' => 'P13b Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization, 'referral_source' => 'manual',
            'source_system' => $sourceSystem, 'full_name' => 'P13b Synthetic', 'phone' => '620000000000',
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
            'code' => $packageCode, 'name' => 'P13b Synthetic', 'amount' => 100,
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
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY'], JSON_THROW_ON_ERROR),
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt', 'attemptPublicId', 'sourceSystem');
    }

    private function consumeAuditCount(): int
    {
        return DB::table('audit_logs')->where('subject_type', AssessmentParticipant::class)
            ->where('subject_id', (string) $this->fixture['attempt'])
            ->where('action', 'checkout_handoff.consumed')->count();
    }
}
