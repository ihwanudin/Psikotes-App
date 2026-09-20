<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\AssessmentSessions\AllocateAndStartAssessmentSession;
use App\Actions\AssessmentSessions\StartParticipantAssessmentSession;
use App\Contracts\AssessmentSessionDefinitionAuthority;
use App\Domain\AssessmentSessions\AssessmentAttemptAllocationPolicy;
use App\Domain\AssessmentSessions\AssessmentSessionDeadlinePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionPolicy;
use App\Domain\AssessmentSessions\AssessmentSessionStateMachine;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\CaseAuthorizationResolver;
use App\Services\AssessmentSessions\ParticipantAssessmentSessionCandidates;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * F2 lane S4 (2026-09-21). Real-PostgreSQL evidence for the ADR-0030 sealed
 * command chain StartParticipantAssessmentSession ->
 * AllocateAndStartAssessmentSession::allocateSelectedParticipantForUpdate(),
 * for exactly what SQLite cannot prove:
 *
 *   1. Genuine inter-process row-lock contention on the participant row two
 *      concurrent DIRECT_PUBLIC starts race on, and that the loser observes
 *      the winner's committed grant as an exact replay (not a duplicate
 *      allocation, not a rejection).
 *   2. A real PostgreSQL-raised trigger failure (a genuine SQLSTATE from the
 *      server, not a synthetic QueryException assembled in PHP) unwinding
 *      every prior write in the allocator's single outer transaction.
 *   3. A real PostgreSQL deadlock (SQLSTATE 40P01, detected and reported by
 *      Postgres's own deadlock detector, not injected) being recognised by
 *      isRetryable() and recovered by the command's own retry loop.
 *
 * What this file deliberately does NOT duplicate:
 *   - Which SQLSTATEs retry, the 3-attempt cap, and that every individual
 *     write phase (definition issue, session insert, grant insert, source
 *     transition, final start) rolls back in isolation: exhaustively proven
 *     driver-agnostically (fault injection via DB::listen matching real SQL
 *     text) in tests/Feature/AssessmentSessions/AllocateAndStartAssessmentSessionTest.php.
 *   - Table-level RLS/GRANT enforcement for test_session_grants (append-only
 *     ledger, service-INSERT-only, no UPDATE/DELETE even for the owning
 *     role): tests/Postgres/TestSessionGrantSecurityTest.php.
 *   - That a non-service RlsContext (or no context at all) is rejected by
 *     the command before touching the database: pure PHP boundary logic,
 *     identical under every driver, proven in
 *     AllocateAndStartAssessmentSessionTest::test_participant_context_is_not_silently_elevated_to_the_internal_service_allocator.
 */
final class StartParticipantAssessmentSessionConcurrencyTest extends TestCase
{
    private const NOW = '2026-09-21T02:00:00.123456+00:00';

    /** @var list<array{branch:int,package:int,participant:int,case:int,order:int,entitlement:int,paymentMethod:int}> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $this->cleanupFixture($fixture);
        }
        $this->fixtures = [];
        parent::tearDown();
    }

    public function test_two_concurrent_direct_public_starts_produce_one_new_session_and_one_exact_replay(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'S4 concurrency evidence requires pcntl; never skip.');
        $fixture = $this->participantGraph();
        DB::purge('pgsql');

        $workers = [$this->startWorker($fixture['participant'], $fixture['branch']), $this->startWorker($fixture['participant'], $fixture['branch'])];

        try {
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }

            $holderIndex = $this->waitForLockHolder($workers);
            $waiterIndex = $holderIndex === 0 ? 1 : 0;
            $this->assertBackendWaitsOnLock($workers[$waiterIndex]['backend']);

            fwrite($workers[$holderIndex]['socket'], "release\n");
            $winner = $this->readEvent($workers[$holderIndex]['socket'], 'result');
            $this->assertSame('locked', $this->readEvent($workers[$waiterIndex]['socket'])['event']);
            fwrite($workers[$waiterIndex]['socket'], "release\n");
            $loser = $this->readEvent($workers[$waiterIndex]['socket'], 'result');

            $this->assertTrue($winner['ok']);
            $this->assertFalse($winner['replayed']);
            $this->assertTrue($loser['ok']);
            $this->assertTrue($loser['replayed']);
            $this->assertSame($winner['sessionId'], $loser['sessionId']);
            $this->assertSame($winner['startedAt'], $loser['startedAt']);
            $this->assertSame($winner['endsAt'], $loser['endsAt']);
            $this->assertTrue($winner['contextClean']);
            $this->assertTrue($loser['contextClean']);
        } finally {
            $this->stopWorkers($workers);
        }

        app(RlsContextRunner::class)->runAsService(function () use ($fixture): void {
            $this->assertSame(1, DB::table('test_sessions')->where('participant_id', $fixture['participant'])->count());
            $this->assertSame('in_progress', DB::table('test_sessions')->where('participant_id', $fixture['participant'])->value('status'));
            $this->assertSame('in_progress', DB::table('entitlements')->where('id', $fixture['entitlement'])->value('status'));
        });
        $this->assertSame(1, $this->ownerCount('test_session_grants', 'participant_id', $fixture['participant']));

        $this->fixtures[] = $fixture;
    }

    public function test_real_postgresql_trigger_failure_on_final_transition_rolls_back_every_prior_write(): void
    {
        $fixture = $this->participantGraph();
        $this->withOwnerConnection(function (): void {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION s4_inject_final_transition_failure() RETURNS trigger
                LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'S4 injected final transition failure';
                END;
                $$
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER s4_final_transition_failure
                BEFORE UPDATE ON test_sessions
                FOR EACH ROW WHEN (NEW.status = 'in_progress')
                EXECUTE FUNCTION s4_inject_final_transition_failure()
                SQL);
        });

        try {
            $authority = new FakeSessionDefinitionAuthority;
            $action = $this->action($authority);

            try {
                $action->execute(
                    new ParticipantPrincipal($fixture['participant'], $fixture['branch']),
                    GenericAssessmentInstrument::Ist,
                );
                $this->fail('The real PostgreSQL trigger failure must escape the command.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->errorInfo[0] ?? null, $exception->getMessage());
            }
        } finally {
            $this->withOwnerConnection(function (): void {
                DB::unprepared('DROP TRIGGER IF EXISTS s4_final_transition_failure ON test_sessions');
                DB::unprepared('DROP FUNCTION IF EXISTS s4_inject_final_transition_failure()');
            });
        }

        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
        app(RlsContextRunner::class)->runAsService(function () use ($fixture): void {
            $this->assertSame(0, DB::table('test_sessions')->where('participant_id', $fixture['participant'])->count());
            $this->assertSame('ready', DB::table('entitlements')->where('id', $fixture['entitlement'])->value('status'));
            $this->assertNull(DB::table('entitlements')->where('id', $fixture['entitlement'])->value('started_at'));
        });
        $this->assertSame(0, $this->ownerCount('test_session_grants', 'participant_id', $fixture['participant']));

        $this->fixtures[] = $fixture;
    }

    public function test_a_real_postgresql_deadlock_is_retried_and_the_second_attempt_succeeds(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'S4 deadlock evidence requires pcntl; never skip.');
        $fixtureA = $this->participantGraph();
        $fixtureB = $this->participantGraph();
        DB::purge('pgsql');
        $lockA = random_int(900_000_000, 999_999_999);
        $lockB = random_int(900_000_000, 999_999_999);

        $workers = [
            $this->startDeadlockWorker($fixtureA['participant'], $fixtureA['branch'], $lockA, $lockB),
            $this->startDeadlockWorker($fixtureB['participant'], $fixtureB['branch'], $lockB, $lockA),
        ];

        try {
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }
            foreach ($workers as $worker) {
                $this->assertSame('holding_first', $this->readEvent($worker['socket'])['event']);
            }
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go2\n");
            }

            $results = [
                $this->readEvent($workers[0]['socket'], 'result'),
                $this->readEvent($workers[1]['socket'], 'result'),
            ];
        } finally {
            $this->stopWorkers($workers);
        }

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
            $this->assertFalse($result['replayed']);
            $this->assertTrue($result['contextClean']);
        }
        $attempts = array_map(static fn (array $result): int => $result['attempts'], $results);
        sort($attempts);
        $this->assertSame([1, 2], $attempts, 'Exactly one worker must be the real deadlock victim and retry once.');

        app(RlsContextRunner::class)->runAsService(function () use ($fixtureA, $fixtureB): void {
            $this->assertSame(1, DB::table('test_sessions')->where('participant_id', $fixtureA['participant'])->count());
            $this->assertSame(1, DB::table('test_sessions')->where('participant_id', $fixtureB['participant'])->count());
            $this->assertSame('in_progress', DB::table('test_sessions')->where('participant_id', $fixtureA['participant'])->value('status'));
            $this->assertSame('in_progress', DB::table('test_sessions')->where('participant_id', $fixtureB['participant'])->value('status'));
        });

        $this->fixtures[] = $fixtureA;
        $this->fixtures[] = $fixtureB;
    }

    /** @return array{pid:int,backend:int,socket:resource} */
    private function startWorker(int $participantId, int $branchId): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create S4 start worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 20);
            try {
                DB::purge('pgsql');
                $identity = DB::selectOne(
                    'SELECT pg_backend_pid() AS pid, current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
                );
                if ($identity->name !== 'psikotes_runtime' || $identity->rolsuper || $identity->rolbypassrls) {
                    throw new RuntimeException('S4 worker must be runtime non-owner without RLS bypass.');
                }
                DB::statement("SET lock_timeout = '12s'");
                DB::statement("SET statement_timeout = '15s'");
                $this->writeEvent($pair[1], ['event' => 'ready', 'backend' => (int) $identity->pid]);
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('S4 start barrier timed out.');
                }

                $authority = new FakeSessionDefinitionAuthority;
                $clock = function () use ($pair): DateTimeImmutable {
                    $this->writeEvent($pair[1], ['event' => 'locked']);
                    if (fgets($pair[1]) !== "release\n") {
                        throw new RuntimeException('S4 lock release barrier timed out.');
                    }

                    return new DateTimeImmutable(self::NOW);
                };
                $action = $this->action($authority, $clock);
                $result = $action->execute(new ParticipantPrincipal($participantId, $branchId), GenericAssessmentInstrument::Ist);
                $this->writeEvent($pair[1], [
                    'event' => 'result', 'ok' => true, 'replayed' => $result->replayed,
                    'sessionId' => $result->sessionId,
                    'startedAt' => $result->startedAt->format('Y-m-d H:i:s.uP'),
                    'endsAt' => $result->endsAt->format('Y-m-d H:i:s.uP'),
                    'contextClean' => app(RlsContextRunner::class)->current() === null && DB::transactionLevel() === 0,
                ]);
            } catch (Throwable $exception) {
                try {
                    $this->writeEvent($pair[1], [
                        'event' => 'result', 'ok' => false, 'error' => $exception::class.': '.$exception->getMessage(),
                    ]);
                } catch (Throwable) {
                    // The parent already owns the failure after closing the barrier socket.
                }
            }
            fclose($pair[1]);
            DB::disconnect('pgsql');
            exit(0);
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 20);
        $ready = $this->readEvent($pair[0], 'ready');

        return ['pid' => $pid, 'backend' => $ready['backend'], 'socket' => $pair[0]];
    }

    /** @return array{pid:int,backend:int,socket:resource} */
    private function startDeadlockWorker(int $participantId, int $branchId, int $firstLock, int $secondLock): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create S4 deadlock worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 20);
            try {
                DB::purge('pgsql');
                DB::selectOne('SELECT pg_backend_pid() AS pid');
                $this->writeEvent($pair[1], ['event' => 'ready']);
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('S4 deadlock start barrier timed out.');
                }

                $calls = 0;
                $authority = new FakeSessionDefinitionAuthority;
                $clock = function () use ($pair, &$calls, $firstLock, $secondLock): DateTimeImmutable {
                    $calls++;
                    if ($calls === 1) {
                        DB::statement('SELECT pg_advisory_xact_lock(?)', [$firstLock]);
                        $this->writeEvent($pair[1], ['event' => 'holding_first']);
                        if (fgets($pair[1]) !== "go2\n") {
                            throw new RuntimeException('S4 deadlock second-lock barrier timed out.');
                        }
                        // One of the two workers is genuinely aborted here by
                        // PostgreSQL's own deadlock detector (real SQLSTATE
                        // 40P01), not by anything this test injects.
                        DB::statement('SELECT pg_advisory_xact_lock(?)', [$secondLock]);
                    }

                    return new DateTimeImmutable(self::NOW);
                };
                $action = $this->action($authority, $clock);
                $result = $action->execute(new ParticipantPrincipal($participantId, $branchId), GenericAssessmentInstrument::Ist);
                $this->writeEvent($pair[1], [
                    'event' => 'result', 'ok' => true, 'replayed' => $result->replayed, 'attempts' => $calls,
                    'contextClean' => app(RlsContextRunner::class)->current() === null && DB::transactionLevel() === 0,
                ]);
            } catch (Throwable $exception) {
                try {
                    $this->writeEvent($pair[1], [
                        'event' => 'result', 'ok' => false, 'error' => $exception::class.': '.$exception->getMessage(),
                    ]);
                } catch (Throwable) {
                    // The parent already owns the failure after closing the barrier socket.
                }
            }
            fclose($pair[1]);
            DB::disconnect('pgsql');
            exit(0);
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 20);
        $this->readEvent($pair[0], 'ready');

        return ['pid' => $pid, 'backend' => 0, 'socket' => $pair[0]];
    }

    /** @param list<array{pid:int,backend:int,socket:resource}> $workers */
    private function waitForLockHolder(array $workers): int
    {
        $read = [$workers[0]['socket'], $workers[1]['socket']];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 10) !== 1) {
            throw new RuntimeException('Exactly one S4 worker should acquire the participant lock first.');
        }
        $readySocket = reset($read);
        if (! is_resource($readySocket)) {
            throw new RuntimeException('S4 lock holder socket is invalid.');
        }
        $holder = $readySocket === $workers[0]['socket'] ? 0 : 1;
        $this->assertSame('locked', $this->readEvent($workers[$holder]['socket'])['event']);

        return $holder;
    }

    private function assertBackendWaitsOnLock(int $backend): void
    {
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backend]);
            if ($waiting?->wait_event_type === 'Lock') {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->assertSame('Lock', $waiting?->wait_event_type);
    }

    /** @param resource $socket
     * @return array<string,mixed>
     */
    private function readEvent($socket, ?string $expected = null): array
    {
        $line = fgets($socket);
        if (! is_string($line)) {
            throw new RuntimeException('S4 worker did not report an event.');
        }
        $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($event) || ! is_string($event['event'] ?? null)) {
            throw new RuntimeException('S4 worker event is invalid.');
        }
        if ($expected !== null) {
            $this->assertSame($expected, $event['event'], json_encode($event, JSON_THROW_ON_ERROR));
        }

        return $event;
    }

    /** @param resource $socket
     * @param  array<string,mixed>  $event
     */
    private function writeEvent($socket, array $event): void
    {
        fwrite($socket, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    }

    /** @param list<array{pid:int,backend:int,socket:resource}> $workers */
    private function stopWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    private function action(
        AssessmentSessionDefinitionAuthority $authority,
        ?Closure $clock = null,
    ): StartParticipantAssessmentSession {
        $contexts = app(RlsContextRunner::class);

        return new StartParticipantAssessmentSession(
            $contexts,
            app(ParticipantAssessmentSessionCandidates::class),
            new AssessmentSessionSelectionPolicy,
            app(CaseAuthorizationResolver::class),
            new AllocateAndStartAssessmentSession(
                $contexts,
                app(CaseAuthorizationResolver::class),
                $authority,
                new AssessmentAttemptAllocationPolicy,
                new AssessmentSessionStateMachine,
                new AssessmentSessionDeadlinePolicy,
                $clock,
            ),
        );
    }

    /** @return array{branch:int,package:int,participant:int,case:int,order:int,entitlement:int,paymentMethod:int} */
    private function participantGraph(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'S4 Synthetic',
                'organization_code' => $key, 'display_name' => 'S4 Synthetic',
            ]);
            $package = DB::table('packages')->insertGetId([
                'code' => 'PKG-'.$key, 'name' => 'S4 Synthetic', 'amount' => 99000,
                'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['dass21', 'ist'] as $sort => $type) {
                DB::table('package_items')->insert([
                    'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
                'package_id' => $package, 'source_system' => 'DIRECT_PUBLIC',
                'full_name' => 'S4 Synthetic', 'phone' => '620000000000',
            ]);
            $publicId = (string) Str::ulid();
            $case = DB::table('assessment_cases')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant,
                'organization_id' => $branch, 'package_id' => $package,
                'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $paymentMethod = DB::table('payment_methods')->insertGetId([
                'code' => 'METHOD-'.$key, 'display_name' => $key, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $settledAt = now()->subMinute();
            $order = DB::table('orders')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $participant,
                'assessment_case_id' => $case, 'payment_method_id' => $paymentMethod,
                'status' => 'paid', 'amount' => 99000, 'currency' => 'IDR', 'paid_at' => $settledAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $entitlementIds = [];
            foreach (['dass21', 'ist'] as $type) {
                $entitlementIds[$type] = DB::table('entitlements')->insertGetId([
                    'participant_id' => $participant, 'order_id' => $order, 'test_type' => $type,
                    'assessment_case_id' => $type === 'dass21' ? null : $case,
                    'status' => 'ready', 'ready_at' => $settledAt, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $entitlement = $entitlementIds['ist'];

            return compact('branch', 'package', 'participant', 'case', 'order', 'entitlement', 'paymentMethod');
        });
    }

    private function withOwnerConnection(callable $callback): void
    {
        $this->assertDisposableDatabase();
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.s4_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('s4_owner');
        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            DB::purge('s4_owner');
            config()->set('database.connections.s4_owner', null);
        }
    }

    private function ownerCount(string $table, string $column, int $value): int
    {
        $count = 0;
        $this->withOwnerConnection(function () use (&$count, $table, $column, $value): void {
            $count = (int) DB::table($table)->where($column, $value)->count();
        });

        return $count;
    }

    /** @param array{branch:int,package:int,participant:int,case:int,order:int,entitlement:int,paymentMethod:int} $fixture */
    private function cleanupFixture(array $fixture): void
    {
        $this->assertDisposableDatabase();
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.s4_cleanup', [...$config, 'username' => 'org_test_owner']);
        $owner = DB::connection('s4_cleanup');

        try {
            $owner->transaction(function () use ($owner, $fixture): void {
                $owner->statement("SET LOCAL session_replication_role = 'replica'");
                $sessionIds = $owner->table('test_sessions')->where('participant_id', $fixture['participant'])->pluck('id');
                $owner->table('test_session_grants')->whereIn('test_session_id', $sessionIds)->delete();
                $owner->table('test_sessions')->where('participant_id', $fixture['participant'])->delete();
                $owner->table('entitlements')->where('participant_id', $fixture['participant'])->delete();
                $owner->table('orders')->where('participant_id', $fixture['participant'])->delete();
                $owner->table('assessment_cases')->where('participant_id', $fixture['participant'])->delete();
                $owner->table('participants')->where('id', $fixture['participant'])->delete();
                $owner->table('package_items')->where('package_id', $fixture['package'])->delete();
                $owner->table('packages')->where('id', $fixture['package'])->delete();
                $owner->table('payment_methods')->where('id', $fixture['paymentMethod'])->delete();
                $owner->table('branches')->where('id', $fixture['branch'])->delete();
            });
        } finally {
            DB::purge('s4_cleanup');
            config()->set('database.connections.s4_cleanup', null);
        }
    }

    private function assertDisposableDatabase(): void
    {
        $runId = getenv('ORG_TEST_RUN_ID');
        $database = DB::selectOne(<<<'SQL'
            SELECT shobj_description(oid, 'pg_database') AS marker
            FROM pg_database
            WHERE datname = current_database()
            SQL);
        if (! is_string($runId) || $runId === '' || $database?->marker !== "ONCAM_ORG_TEST:{$runId}") {
            throw new RuntimeException('S4 concurrency evidence requires the marked disposable database.');
        }
    }
}

final class FakeSessionDefinitionAuthority implements AssessmentSessionDefinitionAuthority
{
    public int $calls = 0;

    public function issueForNewSession(
        GenericAssessmentInstrument $instrument,
        CaseAuthorization $authorization,
        string $sessionPublicId,
    ): SessionDefinition {
        $this->calls++;
        $payload = [
            'instrument' => $instrument->value,
            'version' => 'synthetic-v1',
            'provenance' => 's4-postgres-concurrency-test',
            'total_duration_seconds' => 600,
            'subtests' => [['code' => 'all', 'duration_seconds' => 600, 'item_count' => 10]],
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => null,
        ];
        $payload['checksum'] = SessionDefinition::checksumFor($payload);

        return SessionDefinition::fromArray($payload);
    }
}
