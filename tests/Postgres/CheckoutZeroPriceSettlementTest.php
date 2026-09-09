<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Actions\Integrations\SettleZeroPriceCheckout;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Data\Integrations\CheckoutZeroPriceResult;
use App\Enums\CheckoutHandoffIntent;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** PostgreSQL-authoritative lock, RLS, and rollback evidence for P16-pay-f. */
final class CheckoutZeroPriceSettlementTest extends TestCase
{
    /** @var array<string, int|string> */
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
        config()->set('assessment_integration.checkout.enabled', true);
        $graph = app(RlsContextRunner::class)->runAsService(fn (): array => $this->createFixture());
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(
                IntegrationClient::query()->findOrFail($graph['client']), $graph['attemptPublicId'],
                $graph['sourceSystem'], 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue,
            )));
        $established = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($issued->rawToken()));
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
        if (isset($this->fixture['organization'])) {
            app(RlsContextRunner::class)->runAsService(function (): void {
                $organization = $this->fixture['organization'];
                $participant = $this->fixture['participant'];
                $attempt = $this->fixture['attempt'];
                DB::table('outbox_messages')->where('aggregate_id', (string) $attempt)->delete();
                DB::table('audit_logs')->where('branch_id', $organization)->delete();
                DB::table('assessment_entitlements')->where('assessment_participant_id', $attempt)->delete();
                DB::table('assessment_bill_items')->where('organization_id', $organization)->delete();
                DB::table('assessment_bills')->where('organization_id', $organization)->delete();
                DB::table('assessment_charges')->where('assessment_participant_id', $attempt)->delete();
                DB::table('identity_verifications')->where('participant_id', $participant)->delete();
                DB::table('identity_evidence')->where('participant_id', $participant)->delete();
                DB::table('consent_records')->where('participant_id', $participant)->delete();
                DB::table('checkout_sessions')->where('organization_id', $organization)->delete();
                DB::table('checkout_handoffs')->where('organization_id', $organization)->delete();
                DB::table('integration_sources')->where('integration_client_id', $this->fixture['client'])->delete();
                DB::table('package_items')->where('package_id', $this->fixture['package'])->delete();
            });
        }
        parent::tearDown();
    }

    public function test_two_processes_serialize_to_one_charge_audit_and_activation_set(): void
    {
        $first = $this->startWorker(fn (): array => $this->workerSettlement());
        $second = $this->startWorker(fn (): array => $this->workerSettlement());
        try {
            $firstBackend = $this->workerBackendId($first);
            $secondBackend = $this->workerBackendId($second);
            app(RlsContextRunner::class)->runAsService(function () use ($first, $second, $firstBackend, $secondBackend): void {
                DB::table('branches')->where('id', $this->fixture['organization'])->lockForUpdate()->first();
                fwrite($first['socket'], "go\n");
                fwrite($second['socket'], "go\n");
                $this->assertWorkerWaitsOnLock($firstBackend);
                $this->assertWorkerWaitsOnLock($secondBackend);
            });
            $results = [$this->workerResult($first), $this->workerResult($second)];
            $this->assertSame(['settled', 'settled'], array_column($results, 'state'),
                json_encode($results, JSON_THROW_ON_ERROR));
        } finally {
            $this->stopWorker($first);
            $this->stopWorker($second);
        }

        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(1, DB::table('assessment_charges')
                ->where('assessment_participant_id', $this->fixture['attempt'])->whereNotNull('free_settled_at')->count());
            $this->assertSame(1, DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])
                ->where('action', 'assessment_charge.free_settled')->count());
            $this->assertSame(2, DB::table('assessment_entitlements')
                ->where('assessment_participant_id', $this->fixture['attempt'])->where('status', 'ready')->count());
            $this->assertSame(1, DB::table('outbox_messages')->where('aggregate_id', (string) $this->fixture['attempt'])
                ->where('topic', 'assessment.activation')->count());
        });
    }

    public function test_policy_and_consent_writers_serialize_then_stale_checkout_rejects(): void
    {
        foreach (['policy', 'consent'] as $case) {
            if ($case === 'consent') {
                $this->resetPolicy();
            }
            $worker = $this->startWorker(fn (): array => $this->workerSettlement());
            try {
                $backend = $this->workerBackendId($worker);
                app(RlsContextRunner::class)->runAsService(function () use ($case, $worker, $backend): void {
                    $query = $case === 'policy'
                        ? DB::table('branches')->where('id', $this->fixture['organization'])
                        : DB::table('consent_records')->where('participant_id', $this->fixture['participant'])
                            ->where('consent_type', 'dass');
                    $query->lockForUpdate()->first();
                    fwrite($worker['socket'], "go\n");
                    $this->assertWorkerWaitsOnLock($backend);
                    if ($case === 'policy') {
                        DB::table('branches')->where('id', $this->fixture['organization'])->update([
                            'allowed_payer_types' => json_encode(['organization'], JSON_THROW_ON_ERROR),
                        ]);
                    } else {
                        DB::table('consent_records')->where('participant_id', $this->fixture['participant'])
                            ->where('consent_type', 'dass')->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
                    }
                });
                $this->assertSame('unavailable', $this->workerResult($worker)['state']);
            } finally {
                $this->stopWorker($worker);
            }
            app(RlsContextRunner::class)->runAsService(function (): void {
                $this->assertSame(0, DB::table('assessment_charges')
                    ->where('assessment_participant_id', $this->fixture['attempt'])->count());
                $this->assertSame(0, DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])
                    ->where('action', 'assessment_charge.free_settled')->count());
            });
        }
    }

    public function test_failure_after_activation_outbox_rolls_back_the_outer_transaction(): void
    {
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'audit_logs')
                && in_array('assessment.activated', $query->bindings, true)) {
                $armed = false;
                throw new RuntimeException('synthetic zero activation failure');
            }
        });
        try {
            try {
                $this->settle();
                $this->fail('Injected post-outbox activation failure did not propagate.');
            } catch (Throwable $exception) {
                $this->assertStringContainsString('synthetic zero activation failure', $exception->getMessage());
            }
        } finally {
            $armed = false;
        }

        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(0, DB::table('assessment_charges')
                ->where('assessment_participant_id', $this->fixture['attempt'])->count());
            $this->assertSame(0, DB::table('assessment_entitlements')
                ->where('assessment_participant_id', $this->fixture['attempt'])->count());
            $this->assertSame(0, DB::table('outbox_messages')
                ->where('aggregate_id', (string) $this->fixture['attempt'])->count());
            $this->assertSame(0, DB::table('audit_logs')->where('branch_id', $this->fixture['organization'])
                ->whereIn('action', ['assessment_charge.free_settled', 'assessment.activated'])->count());
            $this->assertSame('PROVISIONED', DB::table('assessment_participants')
                ->where('id', $this->fixture['attempt'])->value('assessment_status'));
        });
    }

    /** @return array{state:string,activated?:list<string>} */
    private function workerSettlement(): array
    {
        try {
            $result = $this->settle();

            return ['state' => $result->state, 'activated' => $result->activatedTestTypes];
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'CHECKOUT_PAYMENT_UNAVAILABLE') {
                throw $exception;
            }

            return ['state' => 'unavailable'];
        }
    }

    private function settle(): CheckoutZeroPriceResult
    {
        return app(SettleZeroPriceCheckout::class)->execute(new CheckoutSessionMutationCredentials(
            (string) $this->fixture['selector'],
            (string) $this->fixture['csrf'],
        ), false);
    }

    private function resetPolicy(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('branches')->where('id', $this->fixture['organization'])->update([
                'allowed_payer_types' => json_encode(['self'], JSON_THROW_ON_ERROR),
            ]);
        });
    }

    /** @param callable():array<string,mixed> $callback
     * @return array{pid:int,socket:resource}
     */
    private function startWorker(callable $callback): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'P16-pay-f requires pcntl; never skip.');
        DB::purge('pgsql');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create zero-price worker.');
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
                    throw new RuntimeException('Zero-price worker barrier timed out.');
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
        $sourceSystem = 'P16PAYF_'.$key;
        $packageCode = 'PF'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'P16 Zero', 'organization_code' => $key,
            'display_name' => 'P16 Zero', 'status' => 'ACTIVE', 'is_active' => true,
            'allowed_payer_types' => json_encode(['self'], JSON_THROW_ON_ERROR),
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => 'P16 Zero', 'birth_date' => '2000-01-02', 'gender' => 'female',
            'education_level' => 'SMA_SMK', 'intended_field' => 'KAIGO', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'allowed_payer_types' => json_encode(['self'], JSON_THROW_ON_ERROR),
            'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'P16 Zero', 'amount' => 0,
            'consultation_amount' => 30, 'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('participants')->where('id', $participant)->update(['package_id' => $package]);
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
        foreach (['psychotest', 'dass'] as $type) {
            $document = ConsentDocument::for($type);
            DB::table('consent_records')->insert([
                'participant_id' => $participant, 'consent_type' => $type, 'status' => 'accepted',
                'document_version' => $document->version, 'document_hash' => $document->hash,
                'consented_at' => now(), 'withdrawn_at' => null,
            ]);
        }
        foreach (['identity_document', 'initial_selfie'] as $type) {
            $publicId = (string) Str::ulid();
            DB::table('identity_evidence')->insert([
                'public_id' => $publicId, 'participant_id' => $participant, 'type' => $type,
                'disk' => 'local', 'object_key' => 'synthetic/'.$publicId, 'mime_type' => 'image/jpeg',
                'size_bytes' => 100, 'width' => 10, 'height' => 10,
                'checksum_sha256' => hash('sha256', $publicId), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('identity_verifications')->insert([
            'participant_id' => $participant, 'matcher' => 'synthetic', 'outcome' => 'match',
            'manual_status' => 'pending', 'checked_at' => now(),
        ]);

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt',
            'attemptPublicId', 'sourceSystem');
    }
}
